<?php

declare(strict_types=1);

use App\Modules\Cash\Models\Movement;
use App\Modules\Domains\Contributions\DomainsContactPanel;
use App\Modules\Domains\Contributions\DomainsWebsitePanel;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Filament\Resources\Domains\Pages\CreateDomain;
use App\Modules\Domains\Filament\Resources\Domains\Pages\ListDomains;
use App\Modules\Domains\Filament\Resources\Domains\Pages\ViewDomain;
use App\Modules\Domains\Filament\Widgets\ContactDomainsTable;
use App\Modules\Domains\Filament\Widgets\DomainsExpiringWidget;
use App\Modules\Domains\Filament\Widgets\WebsiteDomainsTable;
use App\Modules\Domains\Models\Domain;
use App\Modules\Websites\Contracts\WebsitePanel;
use App\Modules\Websites\Models\Website;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use Tests\Feature\Invoicing\Studio;

beforeEach(function (): void {
    Studio::configure();
    signInAsOwner();
});

it('offers to register a domain from the contact page, both from the section and from its empty state', function (): void {
    $client = Studio::client();
    $url = CreateDomain::prefilledUrl(['contact_id' => $client->id]);

    expect($url)->toEndWith("/admin/domains/create?contact_id={$client->id}")
        ->and((new DomainsContactPanel)->createAction($client->id)?->getUrl())->toBe($url);

    Livewire::test(ContactDomainsTable::class, ['contactId' => $client->id])
        ->assertSee('Domini')
        ->assertSee('Nessun dominio')
        ->assertSee('La scadenza entra nella previsione di cassa')
        ->assertSee('Nuovo dominio')
        ->assertSee("contact_id={$client->id}", escape: false);

    Domain::factory()->create(['contact_id' => $client->id, 'name' => 'bellavista.example']);

    Livewire::test(ContactDomainsTable::class, ['contactId' => $client->id])
        ->assertDontSee('Nessun dominio')
        ->assertSee('bellavista.example')
        ->assertSee('Nuovo dominio')
        ->assertSee("contact_id={$client->id}", escape: false);
});

it('offers to register a domain from the website page, already knowing the site and its client', function (): void {
    $client = Studio::client();
    $website = Website::factory()->create(['contact_id' => $client->id]);
    $url = CreateDomain::prefilledUrl(['contact_id' => $client->id, 'website_id' => $website->id]);

    expect($url)->toEndWith("/admin/domains/create?contact_id={$client->id}&website_id={$website->id}")
        ->and((new DomainsWebsitePanel)->createAction($website->id)?->getUrl())->toBe($url)
        ->and((new DomainsWebsitePanel)->key())->toBe('domains')
        ->and(array_map(fn (WebsitePanel $panel): string => $panel->key(), iterator_to_array(app()->tagged(WebsitePanel::TAG), false)))->toContain('domains');

    $this->get("/admin/websites/{$website->id}")->assertOk()
        ->assertSee('Domini')
        ->assertSee('Nuovo dominio')
        ->assertSee("website_id={$website->id}", escape: false)
        ->assertSee("contact_id={$client->id}", escape: false);

    Livewire::test(WebsiteDomainsTable::class, ['websiteId' => $website->id])
        ->assertSee('Nessun dominio')
        ->assertSee('Nuovo dominio')
        ->assertSee("website_id={$website->id}", escape: false)
        ->assertSee("contact_id={$client->id}", escape: false);

    Domain::factory()->create(['contact_id' => $client->id, 'website_id' => $website->id, 'name' => 'bellavista.example']);
    Domain::factory()->create(['contact_id' => $client->id, 'name' => 'elsewhere.example']);

    Livewire::test(WebsiteDomainsTable::class, ['websiteId' => $website->id])
        ->assertDontSee('Nessun dominio')
        ->assertSee('bellavista.example')
        ->assertDontSee('elsewhere.example');
});

it('opens the create form already knowing the client and the site it was reached from', function (): void {
    $client = Studio::client();
    $website = Website::factory()->create(['contact_id' => $client->id]);

    $this->get("/admin/domains/create?contact_id={$client->id}&website_id={$website->id}")->assertOk()->assertSee('Pasticceria Bellavista');

    Livewire::withQueryParams(['contact_id' => (string) $client->id, 'website_id' => (string) $website->id])
        ->test(CreateDomain::class)
        ->assertSchemaStateSet(['contact_id' => $client->id, 'website_id' => $website->id, 'renewal_months' => 12, 'auto_renew' => true]);

    Livewire::withQueryParams(['contact_id' => (string) $client->id])
        ->test(CreateDomain::class)
        ->assertSchemaStateSet(['contact_id' => $client->id, 'website_id' => null]);

    Livewire::withQueryParams(['contact_id' => 'abc', 'website_id' => '-1'])
        ->test(CreateDomain::class)
        ->assertSchemaStateSet(['contact_id' => null, 'website_id' => null]);

    // Picking the site first fills the client in; a chosen client narrows the sites to theirs.
    $otherSite = Website::factory()->create(['contact_id' => Studio::client(['vat_number' => '12345678903', 'display_name' => 'Altro', 'business_name' => 'Altro'])->id, 'host' => 'www.altro.example']);
    Livewire::test(CreateDomain::class)
        ->fillForm(['website_id' => $website->id])
        ->assertSchemaStateSet(['contact_id' => $client->id])
        ->assertSchemaComponentExists('website_id', checkComponentUsing: fn (Select $select): bool => array_keys($select->getOptions()) === [$website->id]);
});

it('says whose the domain is, where to set a missing cost, and which movement paid each renewal', function (): void {
    $this->travelTo('2026-03-15 10:00:00');
    $client = Studio::client();
    $account = Studio::account();
    $domain = Domain::factory()->create(['contact_id' => $client->id, 'name' => 'bellavista.example', 'renewal_cost_cents' => null, 'account_id' => $account->id]);

    $this->get("/admin/domains/{$domain->id}")->assertOk()
        ->assertSee('Pasticceria Bellavista')
        ->assertSee("/admin/contacts/{$client->id}", escape: false)
        ->assertSee('nessuno · collega')
        ->assertSee('costo da impostare')
        ->assertSee("/admin/domains/{$domain->id}/edit", escape: false);
    Livewire::test(ViewDomain::class, ['record' => $domain->id])->assertActionVisible('renewal')->assertActionDisabled('renewal');

    $domain->forceFill(['renewal_cost_cents' => 1_500])->save();
    Livewire::test(ViewDomain::class, ['record' => $domain->id])->assertActionEnabled('renewal')
        ->callAction('renewal', ['paid_on' => '2026-03-20', 'account_id' => $account->id])->assertNotified('Rinnovo registrato');

    $movement = Movement::query()->sole();
    $this->get("/admin/domains/{$domain->id}")->assertOk()->assertSee("/admin/cash/movements/{$movement->id}/edit", escape: false);
});

it('lists the active domains first with their client and site, and the dashboard stat leads to them', function (): void {
    $this->travelTo('2026-03-15 10:00:00');
    $client = Studio::client();
    $website = Website::factory()->create(['contact_id' => $client->id, 'name' => 'Sito Bellavista']);
    $soon = Domain::factory()->create(['contact_id' => $client->id, 'website_id' => $website->id, 'name' => 'soon.example', 'expires_on' => '2026-04-01']);
    $gone = Domain::factory()->create(['contact_id' => $client->id, 'name' => 'gone.example', 'expires_on' => '2020-01-01', 'status' => DomainStatus::Released]);

    Livewire::test(ListDomains::class)
        ->assertCanSeeTableRecords([$soon])->assertCanNotSeeTableRecords([$gone])
        ->assertSee('Pasticceria Bellavista')->assertSee("/admin/contacts/{$client->id}", escape: false)
        ->assertSee('Sito Bellavista')->assertSee("/admin/websites/{$website->id}", escape: false)
        ->removeTableFilter('status')->assertCanSeeTableRecords([$soon, $gone]);

    Livewire::test(DomainsExpiringWidget::class)->assertSee("/admin/domains/{$soon->id}", escape: false);

    Domain::factory()->create(['contact_id' => $client->id, 'name' => 'also.example', 'expires_on' => '2026-04-02']);
    Livewire::test(DomainsExpiringWidget::class)->assertSee('filters%5B', escape: false)->assertDontSee("/admin/domains/{$soon->id}", escape: false);
});
