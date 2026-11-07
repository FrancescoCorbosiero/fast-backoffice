<?php

declare(strict_types=1);

use App\Modules\Domains\Contributions\DomainsContactPanel;
use App\Modules\Domains\Contributions\DomainsWebsitePanel;
use App\Modules\Domains\Filament\Resources\Domains\Pages\CreateDomain;
use App\Modules\Domains\Filament\Widgets\ContactDomainsTable;
use App\Modules\Domains\Filament\Widgets\WebsiteDomainsTable;
use App\Modules\Domains\Models\Domain;
use App\Modules\Websites\Contracts\WebsitePanel;
use App\Modules\Websites\Models\Website;
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
});
