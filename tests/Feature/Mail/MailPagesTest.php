<?php

declare(strict_types=1);

use App\Modules\Contacts\Models\Contact;
use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Modules\Mail\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Modules\Mail\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Modules\Mail\Filament\Widgets\CampaignRecipientsTable;
use App\Modules\Mail\Filament\Widgets\ContactCampaignsTable;
use App\Modules\Mail\Jobs\SendCampaignEmail;
use App\Modules\Mail\Models\Campaign;
use App\Modules\Settings\Actions\SaveMailSettings;
use App\Modules\Settings\Data\MailSettings;
use App\Modules\Settings\Filament\Pages\MailSettingsPage;
use App\Modules\Settings\Queries\GetMailSettings;
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
    $this->get("/admin/campaigns/{$campaign->id}")->assertOk()->assertSee('1 in coda');
    Livewire::test(CampaignRecipientsTable::class, ['campaignId' => $campaign->id])->assertSee('a@example.test');

    Livewire::test(ViewCampaign::class, ['record' => $campaign->id])->callAction('pause')->assertNotified()->callAction('resume')->assertNotified()->callAction('cancel')->assertNotified();
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Cancelled);

    $this->get("/admin/contacts/{$contact->id}")->assertOk()->assertSee('Campagne email');
    Livewire::test(ContactCampaignsTable::class, ['contactId' => $contact->id])->assertSee('Primavera');

    $draft = Campaign::factory()->create();
    Livewire::test(ViewCampaign::class, ['record' => $draft->id])->callAction('schedule', ['scheduled_at' => '2026-03-20 09:00:00'])->assertNotified();
    expect($draft->refresh()->status)->toBe(CampaignStatus::Scheduled);
    Livewire::test(ViewCampaign::class, ['record' => $draft->id])->callAction('cancel')->assertNotified();
    $another = Campaign::factory()->create();
    Livewire::test(ViewCampaign::class, ['record' => $another->id])->callAction('delete')->assertRedirect();
    expect(Campaign::query()->whereKey($another->id)->exists())->toBeFalse();
});
