<?php

declare(strict_types=1);

use App\Modules\Contacts\Actions\SetEmailConsent;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Queries\SearchContacts;
use App\Modules\Mail\Actions\PauseCampaign;
use App\Modules\Mail\Actions\SaveCampaign;
use App\Modules\Mail\Actions\SendToRecipient;
use App\Modules\Mail\Actions\StartCampaign;
use App\Modules\Mail\Data\CampaignInput;
use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Events\CampaignCompleted;
use App\Modules\Mail\Integrations\CampaignMessage;
use App\Modules\Mail\Jobs\ReconcileCampaigns;
use App\Modules\Mail\Jobs\SendCampaignEmail;
use App\Modules\Mail\Jobs\StartScheduledCampaigns;
use App\Modules\Mail\MailServiceProvider;
use App\Modules\Mail\Models\Campaign;
use App\Modules\Mail\Models\CampaignRecipient;
use App\Modules\Mail\Queries\Campaigns;
use App\Modules\Settings\Actions\SaveMailSettings;
use App\Modules\Settings\Data\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function mailSettings(): void
{
    app(SaveMailSettings::class)->save(new MailSettings('studio@example.test', 'Lo Studio', 'ciao@example.test', ratePerSecond: 5, retryHours: 24, transientBouncesBeforeSuppression: 2));
}

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');
    config()->set('mail-campaigns.mailer', 'array');
});

it('builds the audience from consenting contacts in the chosen roles, one row per address', function (): void {
    mailSettings();
    $client = Contact::factory()->create(['email' => 'a@example.test']);
    $clientToo = Contact::factory()->create(['email' => 'A@Example.test']);
    $supplier = Contact::factory()->supplier()->create(['email' => 'b@example.test']);
    Contact::factory()->withoutConsent()->create(['email' => 'c@example.test']);
    Contact::factory()->suppressed()->create(['email' => 'd@example.test']);
    Contact::factory()->create(['email' => null]);

    expect(array_map(fn ($c) => $c->email, app(SearchContacts::class)->mailable()))->toEqualCanonicalizing(['a@example.test', 'A@Example.test', 'b@example.test'])
        ->and(array_map(fn ($c) => $c->id, app(SearchContacts::class)->mailable([ContactRole::Supplier])))->toBe([$supplier->id]);

    Queue::fake();
    $campaign = app(SaveCampaign::class)->create(CampaignInput::fromArray(['name' => 'Primavera', 'subject' => 'Novità', 'body_html' => '<p>Ciao</p>', 'audience_roles' => ['client']]));
    $started = app(StartCampaign::class)->start($campaign->id);

    expect($started->status)->toBe(CampaignStatus::Sending)
        ->and($started->total())->toBe(1)
        ->and(CampaignRecipient::query()->count())->toBe(1)
        ->and(CampaignRecipient::query()->sole()->contact_id)->toBeIn([$client->id, $clientToo->id]);
    Queue::assertPushed(SendCampaignEmail::class, 1);

    expect(fn () => app(StartCampaign::class)->start($campaign->id))->toThrow(LogicException::class);
    $empty = app(SaveCampaign::class)->create(CampaignInput::fromArray(['name' => 'Vuota', 'subject' => 'x', 'body_html' => '<p>x</p>', 'audience_roles' => ['partner']]));
    expect(fn () => app(StartCampaign::class)->start($empty->id))->toThrow(ValidationException::class)
        ->and(Campaign::query()->findOrFail($empty->id)->status)->toBe(CampaignStatus::Draft);
});

it('refuses to start without a sender, and keeps drafts editable only until they go out', function (): void {
    $campaign = app(SaveCampaign::class)->create(CampaignInput::fromArray(['name' => 'x', 'subject' => 'y', 'body_html' => '<p>z</p>']));
    expect(fn () => app(StartCampaign::class)->start($campaign->id))->toThrow(ValidationException::class);

    expect(fn () => app(SaveCampaign::class)->create(CampaignInput::fromArray(['name' => '', 'subject' => '', 'body_html' => ' '])))->toThrow(ValidationException::class);

    mailSettings();
    Contact::factory()->create();
    Queue::fake();
    app(StartCampaign::class)->start($campaign->id);
    expect(fn () => app(SaveCampaign::class)->update($campaign->id, CampaignInput::fromArray(['name' => 'y', 'subject' => 'y', 'body_html' => '<p>y</p>'])))->toThrow(LogicException::class)
        ->and(fn () => app(SaveCampaign::class)->delete($campaign->id))->toThrow(LogicException::class);
});

it('sends one email per recipient with our Message-ID and one-click unsubscribe headers, once', function (): void {
    mailSettings();
    Mail::fake();
    Event::fake([CampaignCompleted::class]);
    $contact = Contact::factory()->create(['email' => 'giulia@verdi.example']);
    Queue::fake();
    $campaign = app(SaveCampaign::class)->create(CampaignInput::fromArray(['name' => 'x', 'subject' => 'Novità di primavera', 'body_html' => '<p>Ciao <b>Giulia</b></p>', 'preheader' => 'Tre idee']));
    app(StartCampaign::class)->start($campaign->id);
    $recipient = CampaignRecipient::query()->sole();

    $sent = app(SendToRecipient::class)->send($recipient->id);

    expect($sent->status)->toBe(RecipientStatus::Sent)->and($sent->attempts)->toBe(1)->and($sent->messageId)->toContain('.campaign-'.$campaign->id.'@')->and($sent->sentAt)->not->toBeNull();
    Mail::assertSent(CampaignMessage::class, function (CampaignMessage $message) use ($recipient, $sent): bool {
        $headers = $message->headers();

        return $message->hasTo('giulia@verdi.example')
            && $message->hasFrom('studio@example.test', 'Lo Studio')
            && $message->hasReplyTo('ciao@example.test')
            && $message->hasSubject('Novità di primavera')
            && $headers->messageId === $sent->messageId
            && $headers->text['List-Unsubscribe'] === '<'.route('mail.unsubscribe', ['token' => $recipient->unsubscribe_token]).'>'
            && $headers->text['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click';
    });
    $rendered = Mail::sent(CampaignMessage::class)->first();
    expect($rendered)->not->toBeNull();
    $html = $rendered?->render() ?? '';
    expect($html)->toContain('Ciao <b>Giulia</b>')->toContain('Tre idee')->toContain('/unsubscribe/'.$recipient->unsubscribe_token);

    // Done: the campaign is sent only now, and sending again does nothing.
    expect(Campaign::query()->findOrFail($campaign->id)->status)->toBe(CampaignStatus::Sent);
    Event::assertDispatchedTimes(CampaignCompleted::class, 1);
    app(SendToRecipient::class)->send($recipient->id);
    Mail::assertSentCount(1);
});

it('skips a recipient who withdrew consent in the meantime, and does nothing while paused', function (): void {
    mailSettings();
    Mail::fake();
    $contact = Contact::factory()->create(['email' => 'a@example.test']);
    $other = Contact::factory()->create(['email' => 'b@example.test']);
    Queue::fake();
    $campaign = app(SaveCampaign::class)->create(CampaignInput::fromArray(['name' => 'x', 'subject' => 'y', 'body_html' => '<p>z</p>']));
    app(StartCampaign::class)->start($campaign->id);
    app(SetEmailConsent::class)->set($contact->id, false, 'owner');
    $first = CampaignRecipient::query()->where('contact_id', $contact->id)->sole();
    $second = CampaignRecipient::query()->where('contact_id', $other->id)->sole();

    expect(app(SendToRecipient::class)->send($first->id)->status)->toBe(RecipientStatus::Skipped);
    Mail::assertNothingSent();

    app(PauseCampaign::class)->pause($campaign->id);
    expect(app(SendToRecipient::class)->send($second->id)->status)->toBe(RecipientStatus::Queued);
    Mail::assertNothingSent();

    Queue::fake();
    app(PauseCampaign::class)->resume($campaign->id);
    Queue::assertPushed(SendCampaignEmail::class, fn (SendCampaignEmail $job): bool => $job->recipientId === $second->id);
    expect(app(SendToRecipient::class)->send($second->id)->status)->toBe(RecipientStatus::Sent);
    Mail::assertSentCount(1);
    expect(Campaign::query()->findOrFail($campaign->id)->status)->toBe(CampaignStatus::Sent);
});

it('counts a failed attempt, keeps the error and lets the queue retry within a time budget; cancelling skips the rest', function (): void {
    mailSettings();
    Contact::factory()->create(['email' => 'a@example.test']);
    Contact::factory()->create(['email' => 'b@example.test']);
    Queue::fake();
    $campaign = app(SaveCampaign::class)->create(CampaignInput::fromArray(['name' => 'x', 'subject' => 'y', 'body_html' => '<p>z</p>']));
    app(StartCampaign::class)->start($campaign->id);
    [$first, $second] = CampaignRecipient::query()->orderBy('id')->get()->all();

    config()->set('mail-campaigns.mailer', 'broken');
    config()->set('mail.mailers.broken', ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]);

    expect(fn () => app(SendToRecipient::class)->send($first->id))->toThrow(RuntimeException::class);
    expect($first->refresh()->status)->toBe(RecipientStatus::Queued)->and($first->attempts)->toBe(1)->and($first->last_error)->not->toBeNull();

    $job = new SendCampaignEmail($first->id);
    expect($job->retryUntil()->getTimestamp())->toBe(CarbonImmutable::now()->addHours(24)->getTimestamp())
        ->and($job->middleware()[0])->toBeInstanceOf(Illuminate\Queue\Middleware\RateLimited::class)
        ->and(Illuminate\Support\Facades\RateLimiter::limiter(MailServiceProvider::RATE_LIMITER))->not->toBeNull();

    $job->failed(new RuntimeException('Tentativi esauriti'));
    expect($first->refresh()->status)->toBe(RecipientStatus::Failed)->and($first->last_error)->toBe('Tentativi esauriti');

    app(PauseCampaign::class)->cancel($campaign->id);
    expect($second->refresh()->status)->toBe(RecipientStatus::Skipped)->and(Campaign::query()->findOrFail($campaign->id)->status)->toBe(CampaignStatus::Cancelled);
});

it('starts scheduled campaigns when their time comes and reconciles the stuck ones', function (): void {
    mailSettings();
    Contact::factory()->create(['email' => 'a@example.test']);
    Queue::fake();
    $campaign = app(SaveCampaign::class)->create(CampaignInput::fromArray(['name' => 'Domani', 'subject' => 'y', 'body_html' => '<p>z</p>']));

    $scheduled = app(StartCampaign::class)->start($campaign->id, CarbonImmutable::parse('2026-03-16 09:00:00'));
    expect($scheduled->status)->toBe(CampaignStatus::Scheduled)->and(app(Campaigns::class)->dueToStart(CarbonImmutable::now()))->toBe([]);
    expect(app(StartScheduledCampaigns::class)->run()->output)->toContain('Nessuna');

    $this->travelTo('2026-03-16 09:05:00');
    $result = app(StartScheduledCampaigns::class)->run();
    expect($result->succeeded())->toBeTrue()->and($result->output)->toContain('Domani: in coda per 1')
        ->and(Campaign::query()->findOrFail($campaign->id)->status)->toBe(CampaignStatus::Sending);
    Queue::assertPushed(SendCampaignEmail::class, 1);

    $this->travelTo('2026-03-18 09:05:00');
    Queue::fake();
    $reconciled = app(ReconcileCampaigns::class)->run();
    expect($reconciled->output)->toContain('1 destinatari rimessi in coda');
    Queue::assertPushed(SendCampaignEmail::class, 1);

    CampaignRecipient::query()->update(['status' => RecipientStatus::Sent->value]);
    expect(app(ReconcileCampaigns::class)->run()->output)->toContain('Domani: completata')
        ->and(Campaign::query()->findOrFail($campaign->id)->status)->toBe(CampaignStatus::Sent);
});
