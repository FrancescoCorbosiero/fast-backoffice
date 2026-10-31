<?php

declare(strict_types=1);

use App\Modules\Cash\Models\Movement;
use App\Modules\Invoicing\Models\RecurringInvoice;
use App\Modules\Websites\Enums\WebsiteStatus;
use App\Modules\Websites\Filament\Resources\Websites\Pages\CreateWebsite;
use App\Modules\Websites\Filament\Resources\Websites\Pages\EditWebsite;
use App\Modules\Websites\Filament\Resources\Websites\Pages\ListWebsites;
use App\Modules\Websites\Filament\Resources\Websites\Pages\ViewWebsite;
use App\Modules\Websites\Filament\Widgets\ContactWebsitesTable;
use App\Modules\Websites\Filament\Widgets\WebsitesStatusWidget;
use App\Modules\Websites\Models\Website;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Invoicing\Studio;

beforeEach(function (): void {
    Studio::configure();
    signInAsOwner();
    $this->travelTo('2026-03-15 10:00:00');
});

it('renders the website screens', function (string $url): void {
    $this->get($url)->assertOk();
})->with(['/admin/websites', '/admin/websites/create']);

it('creates, edits and runs a site from the panel', function (): void {
    $client = Studio::client();
    $account = Studio::account();

    Livewire::test(CreateWebsite::class)
        ->fillForm(['name' => 'Bellavista', 'contact_id' => $client->id, 'url' => 'https://www.bellavista.example', 'hosting_provider' => 'Hetzner', 'hosting_renews_on' => '2026-04-15', 'hosting_frequency' => 'yearly', 'hosting_cost' => '120', 'hosting_account_id' => $account->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $website = Website::query()->sole();
    expect($website->host)->toBe('www.bellavista.example')->and($website->hosting_cost_cents)->toBe(12_000);

    $this->get("/admin/websites/{$website->id}")->assertOk()->assertSee('Bellavista')->assertSee('Hetzner');

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->assertSchemaStateSet(['hosting_cost' => '120.00'])
        ->fillForm(['name' => 'Bellavista Pasticceria'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($website->refresh()->name)->toBe('Bellavista Pasticceria');

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->callAction('renewal', ['paid_on' => '2026-04-14', 'account_id' => $account->id])
        ->assertNotified()
        ->callAction('subscribe', ['frequency' => 'monthly', 'started_on' => '2026-05-01', 'payment_terms_days' => 15, 'auto_issue' => false, 'lines' => [['description' => 'Canone', 'quantity' => '1', 'unit_price' => '50', 'vat_percent' => '0', 'natura' => null]]])
        ->assertNotified();

    expect(Movement::query()->count())->toBe(1)
        ->and($website->refresh()->hosting_renews_on->toDateString())->toBe('2027-04-15')
        ->and(RecurringInvoice::query()->findOrFail($website->subscription_id)->payment_terms_days)->toBe(15);

    $this->get("/admin/websites/{$website->id}")->assertOk()->assertSee('Rinnovo pagato')->assertSee('Canone sito');

    Livewire::test(ViewWebsite::class, ['record' => $website->id])->callAction('unsubscribe')->assertNotified()->callAction('status', ['status' => 'suspended'])->assertNotified();
    expect($website->refresh()->subscription_id)->toBeNull()->and($website->status)->toBe(WebsiteStatus::Suspended);
});

it('checks the sites from the list and shows them on the dashboard and the contact page', function (): void {
    $client = Studio::client();
    $website = Website::factory()->hosted('2026-03-01')->create(['contact_id' => $client->id]);
    Http::fake(['*' => Http::response('', 500)]);

    Livewire::test(ListWebsites::class)->callAction('check')->assertNotified();
    Livewire::test(ListWebsites::class)->callAction('check')->assertNotified();
    expect($website->refresh()->down_since)->not->toBeNull();

    Livewire::test(WebsitesStatusWidget::class)->assertSee('Siti giù')->assertSee('Pasticceria Bellavista')->assertSee('già scaduti');
    $this->get("/admin/contacts/{$client->id}")->assertOk()->assertSee('Siti');
    Livewire::test(ContactWebsitesTable::class, ['contactId' => $client->id])->assertSee('www.bellavista.example');
});
