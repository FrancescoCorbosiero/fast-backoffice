<?php

declare(strict_types=1);

use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Mail\Contributions\CampaignsContactPanel;
use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Filament\Resources\Campaigns\CampaignResource;
use App\Modules\Mail\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Modules\Mail\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Modules\Mail\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Modules\Mail\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Modules\Mail\Filament\Widgets\CampaignRecipientsTable;
use App\Modules\Mail\Filament\Widgets\CampaignsOverviewWidget;
use App\Modules\Mail\Filament\Widgets\ContactCampaignsTable;
use App\Modules\Mail\Integrations\CampaignMessage;
use App\Modules\Mail\Jobs\SendCampaignEmail;
use App\Modules\Mail\Models\Campaign;
use App\Modules\Mail\Models\CampaignRecipient;
use App\Modules\Mail\Models\MailEvent;
use App\Modules\Settings\Actions\SaveMailSettings;
use App\Modules\Settings\Data\MailSettings;
use App\Modules\Settings\Filament\Pages\MailSettingsPage;
use App\Modules\Settings\Queries\GetMailSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    signInAsOwner();
    $this->travelTo('2026-03-15 10:00:00');
});

it('edits the mail settings from their page', function (): void {
    $this->get('/admin/settings/mail')->assertOk();

    Livewire::test(MailSettingsPage::class)
        ->fillForm(['from_address' => 'Studio@Example.test', 'from_name' => 'Lo Studio', 'reply_to' => 'ciao@example.test', 'rate_per_second' => 3, 'retry_hours' => 12, 'transient_bounces_before_suppression' => 2])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(GetMailSettings::class)->get();
    expect($settings->fromAddress)->toBe('studio@example.test')->and($settings->ratePerSecond)->toBe(3)->and($settings->retryHours)->toBe(12)->and($settings->isConfigured())->toBeTrue();
});

it('writes, edits, sends, pauses and cancels a campaign from the panel', function (): void {
    app(SaveMailSettings::class)->save(new MailSettings('studio@example.test', 'Lo Studio'));
    $contact = Contact::factory()->create(['email' => 'a@example.test']);
    Contact::factory()->supplier()->create(['email' => 'b@example.test']);
    Queue::fake();

    $this->get('/admin/campaigns')->assertOk();
    $this->get('/admin/campaigns/create')->assertOk();

    Livewire::test(CreateCampaign::class)
        ->fillForm(['name' => 'Primavera', 'subject' => 'Novità', 'preheader' => 'Tre idee', 'audience_roles' => ['client'], 'body_html' => '<p>Ciao a tutti</p>'])
        ->call('create')
        ->assertHasNoFormErrors();
    $campaign = Campaign::query()->sole();
    expect($campaign->audience_roles)->toBe(['client']);

    $this->get("/admin/campaigns/{$campaign->id}")->assertOk()->assertSee('Primavera')->assertSee('1 contatti');

    Livewire::test(EditCampaign::class, ['record' => $campaign->id])->fillForm(['subject' => 'Novità di primavera'])->call('save')->assertHasNoFormErrors();
    expect($campaign->refresh()->subject)->toBe('Novità di primavera');

    Livewire::test(ViewCampaign::class, ['record' => $campaign->id])->callAction('send')->assertNotified();
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Sending);
    Queue::assertPushed(SendCampaignEmail::class, 1);
    $this->get("/admin/campaigns/{$campaign->id}/edit")->assertForbidden();
    $this->get("/admin/campaigns/{$campaign->id}")->assertOk()->assertSee('In coda')->assertSee(CampaignResource::getUrl('view', ['record' => $campaign->id, 'status' => 'queued']), escape: false);
    Livewire::test(CampaignRecipientsTable::class, ['campaignId' => $campaign->id])->assertSee('a@example.test');

    Livewire::test(ViewCampaign::class, ['record' => $campaign->id])->callAction('pause')->assertNotified()->callAction('resume')->assertNotified()->callAction('cancel')->assertNotified();
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Cancelled);

    $this->get("/admin/contacts/{$contact->id}")->assertOk()->assertSee('Campagne email');
    Livewire::test(ContactCampaignsTable::class, ['contactId' => $contact->id])->assertSee('Primavera');

    $draft = Campaign::factory()->create();
    Livewire::test(ViewCampaign::class, ['record' => $draft->id])->callAction('schedule', ['scheduled_at' => '2026-03-20 09:00:00'])->assertNotified();
    expect($draft->refresh()->status)->toBe(CampaignStatus::Scheduled);
    // Scheduled by mistake: back to draft, nothing lost, no "Annulla" offered.
    Livewire::test(ViewCampaign::class, ['record' => $draft->id])->assertActionHidden('cancel')->callAction('unschedule')->assertNotified();
    expect($draft->refresh()->status)->toBe(CampaignStatus::Draft)->and($draft->scheduled_at)->toBeNull();
    $another = Campaign::factory()->create();
    Livewire::test(ViewCampaign::class, ['record' => $another->id])->callAction('delete')->assertRedirect();
    expect(Campaign::query()->whereKey($another->id)->exists())->toBeFalse();
});

it('explains on the contact page why a contact has received no campaign, and leads to the campaigns', function (): void {
    $mailable = Contact::factory()->create();
    $noEmail = Contact::factory()->create(['email' => null]);
    $noConsent = Contact::factory()->withoutConsent()->create();
    $bounced = Contact::factory()->suppressed('bounce')->create();

    Livewire::test(ContactCampaignsTable::class, ['contactId' => $mailable->id])
        ->assertSee('Campagne email')
        ->assertSee('Nessuna campagna ricevuta')
        ->assertSee('Riceve le campagne: riceverà la prossima campagna rivolta ai contatti con ruolo Cliente.')
        ->assertTableActionHidden('lift')->assertTableActionHidden('consent')
        ->assertSee('Vai alle campagne')
        ->assertSee(CampaignResource::getUrl('index'), escape: false)
        ->assertSee('Nuova campagna')
        ->assertSee(CampaignResource::getUrl('create'), escape: false);

    Livewire::test(ContactCampaignsTable::class, ['contactId' => $noEmail->id])->assertSee('senza un indirizzo email');

    // The fix for "Non riceve" sits in the section itself.
    Livewire::test(ContactCampaignsTable::class, ['contactId' => $noConsent->id])->assertSee('Non riceve: non ha dato il consenso')->callTableAction('consent')->assertNotified();
    expect($noConsent->refresh()->email_consent)->toBeTrue();
    Livewire::test(ContactCampaignsTable::class, ['contactId' => $bounced->id])->assertSee('bloccato')->assertSee('email respinta')->callTableAction('lift')->assertNotified();
    expect($bounced->refresh()->email_suppressed_at)->toBeNull();

    expect(app(CampaignsContactPanel::class)->createAction($mailable->id))->toBeNull()
        ->and(CampaignsContactPanel::mailabilityDescription(999999))->toContain('non è più attivo');
});

it('leads from a campaign to its audience, a proof, a copy, the contact behind a bounce and another try for a failure', function (): void {
    config()->set('mail-campaigns.mailer', 'array');
    $draft = Campaign::factory()->create(['audience_roles' => ['client']]);

    // No sender yet: the page says so and links to the settings; the toast after "Invia ora" does too.
    Livewire::test(ViewCampaign::class, ['record' => $draft->id])->assertSee('Mittente non impostato')->assertSee(route('filament.admin.pages.settings.mail'), escape: false)
        ->callAction('send')->assertNotified();

    app(SaveMailSettings::class)->save(new MailSettings('studio@example.test', 'Lo Studio'));
    $giulia = Contact::factory()->create(['email' => 'giulia@example.test', 'display_name' => 'Giulia Verdi']);
    Contact::factory()->withoutConsent()->create(['email' => 'nope@example.test']);

    // The audience by name, each a link to the contact; the form says how many each role has.
    $page = Livewire::test(ViewCampaign::class, ['record' => $draft->id])->assertDontSee('Mittente non impostato')->assertSee('1 contatti')->mountAction('audience')->assertActionMounted('audience');
    $audience = (string) $page->instance()->getMountedAction()?->getModalContent();
    expect($audience)->toContain('Giulia Verdi')->toContain(ContactLink::url($giulia->id))->not->toContain('nope@example.test');
    Livewire::test(CreateCampaign::class)->assertSee('Cliente (1)')->assertSee('Fornitore (0)');

    // A proof goes to the owner, through the campaign mailer, without a recipient row.
    Mail::fake();
    Livewire::test(ViewCampaign::class, ['record' => $draft->id])->callAction('test', ['to' => 'me@example.test'])->assertNotified();
    Mail::assertSent(CampaignMessage::class, fn (CampaignMessage $m): bool => $m->hasTo('me@example.test') && $m->hasSubject('[PROVA] '.$draft->subject));
    expect(CampaignRecipient::query()->count())->toBe(0);

    // Duplica: a new draft with the same content and audience, opened for editing.
    Livewire::test(ViewCampaign::class, ['record' => $draft->id])->callAction('duplicate')->assertRedirect();
    $copy = Campaign::query()->where('name', 'Copia di '.$draft->name)->sole();
    expect($copy->status)->toBe(CampaignStatus::Draft)->and($copy->body_html)->toBe($draft->body_html)->and($copy->audience_roles)->toBe(['client']);

    // Sent: a bounce row names the contact, says the address is blocked and opens the contact; a failed row can be retried.
    Queue::fake();
    Livewire::test(ViewCampaign::class, ['record' => $draft->id])->callAction('send')->assertNotified();
    $recipient = CampaignRecipient::query()->sole();
    $recipient->forceFill(['status' => RecipientStatus::Bounced, 'last_error' => 'Bounce General'])->save();
    $giulia->forceFill(['email_suppressed_at' => now(), 'email_suppression_reason' => 'bounce'])->save();
    $draft->forceFill(['status' => CampaignStatus::Sent, 'completed_at' => now()])->save();
    MailEvent::query()->create(['sns_message_id' => 'sns-1', 'event_type' => 'Bounce', 'recipient_id' => $recipient->id, 'outcome' => 'processed', 'received_at' => now()]);

    Livewire::test(ViewCampaign::class, ['record' => $draft->id])->assertSee('Bounce')->assertSee(CampaignResource::getUrl('view', ['record' => $draft->id, 'status' => 'bounced']), escape: false)->assertDontSee(CampaignResource::getUrl('view', ['record' => $draft->id, 'status' => 'delivered']), escape: false);
    Livewire::test(CampaignRecipientsTable::class, ['campaignId' => $draft->id, 'status' => 'bounced'])
        ->assertSee('Giulia Verdi')->assertSee('email bloccata il 15 mar 2026')->assertSee(ContactLink::url($giulia->id), escape: false)
        ->assertTableActionVisible('contact', $recipient)->assertTableActionHidden('requeue', $recipient);
    Livewire::test(CampaignRecipientsTable::class, ['campaignId' => $draft->id, 'status' => 'delivered'])->assertDontSee('Giulia Verdi');

    $recipient->forceFill(['status' => RecipientStatus::Failed, 'last_error' => 'Tentativi esauriti'])->save();
    Livewire::test(CampaignRecipientsTable::class, ['campaignId' => $draft->id])->callTableAction('requeue', $recipient)->assertNotified('1 destinatario rimesso in coda');
    expect($recipient->refresh()->status)->toBe(RecipientStatus::Queued)->and($recipient->last_error)->toBeNull()->and($draft->refresh()->status)->toBe(CampaignStatus::Sending);
    Queue::assertPushed(SendCampaignEmail::class, 2);

    // The dashboard and the SES log say what is going on.
    expect(CampaignsOverviewWidget::canView())->toBeTrue();
    Livewire::test(CampaignsOverviewWidget::class)->assertSee('Campagne in invio')->assertSee('1 email in coda');
    Livewire::test(ListCampaigns::class)->assertSee('Ultimo evento SES: Bounce')->assertSee('Eventi SES');
    $this->get('/admin/mail-events')->assertOk()->assertSee('Applicato')->assertSee('giulia@example.test');
    Livewire::test(ListCampaigns::class)->callTableAction('duplicate', $draft)->assertRedirect();
    expect(Campaign::query()->where('name', 'Copia di '.$draft->name)->count())->toBe(2);
});

it('hides the campaigns dashboard widget when there is nothing to say', function (): void {
    Campaign::factory()->create();
    expect(CampaignsOverviewWidget::canView())->toBeFalse();
});
