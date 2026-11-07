<?php

declare(strict_types=1);

use App\Modules\Contacts\Models\Contact;
use App\Modules\Domains\Models\Domain;
use App\Modules\Leads\Models\Lead;
use App\Modules\Quotes\Models\Quote;
use App\Modules\Websites\Models\Website;
use Filament\Facades\Filament;
use Filament\Livewire\GlobalSearch;
use Livewire\Livewire;
use Tests\Feature\Invoicing\Studio;

beforeEach(function (): void {
    Studio::configure();
    signInAsOwner();
    $this->travelTo('2026-03-15 10:00:00');
});

it('runs the panel as a single-page app with a collapsible sidebar and a keyboard shortcut for search', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->hasSpaMode())->toBeTrue()
        ->and($panel->isSidebarCollapsibleOnDesktop())->toBeTrue()
        ->and($panel->hasUnsavedChangesAlerts())->toBeTrue()
        ->and($panel->getBrandName())->toBe('fast')
        ->and($panel->getGlobalSearchKeyBindings())->toBe(['command+k', 'ctrl+k'])
        ->and($panel->getGlobalSearchProvider())->not->toBeNull();

    $this->get('/admin')->assertOk()->assertSee('wire:navigate', escape: false);
});

it('finds a contact by name, VAT number, tax code and city', function (): void {
    $company = Studio::client();
    Contact::factory()->withTaxCode('VRDLGU80A01F205X')->create(['display_name' => 'Luigi Verdi', 'first_name' => 'Luigi', 'last_name' => 'Verdi', 'city' => 'Bergamo']);
    Contact::factory()->create(['display_name' => 'Anna Bianchi', 'first_name' => 'Anna', 'last_name' => 'Bianchi', 'city' => 'Torino', 'merged_into_id' => $company->id]);

    Livewire::test(GlobalSearch::class)->set('search', 'Bellavista')
        ->assertSee('Pasticceria Bellavista S.r.l.')->assertSee('Cliente')->assertSee('Milano')
        ->assertSee("/admin/contacts/{$company->id}", escape: false)
        ->assertDontSee('Luigi Verdi');

    Livewire::test(GlobalSearch::class)->set('search', '04827193048')->assertSee('Pasticceria Bellavista S.r.l.');
    Livewire::test(GlobalSearch::class)->set('search', 'VRDLGU80A01F205X')->assertSee('Luigi Verdi');
    Livewire::test(GlobalSearch::class)->set('search', 'Bergamo')->assertSee('Luigi Verdi')->assertDontSee('Pasticceria Bellavista');

    // A merged duplicate is gone from the panel, search included.
    Livewire::test(GlobalSearch::class)->set('search', 'Bianchi')->assertDontSee('Anna Bianchi');
});

it('finds a website by host, a domain by name and a lead by company', function (): void {
    $client = Studio::client();
    $website = Website::factory()->create(['contact_id' => $client->id]);
    $domain = Domain::factory()->create(['contact_id' => $client->id, 'name' => 'bellavista.it', 'registrar' => 'OVH', 'expires_on' => '2026-09-30']);
    $lead = Lead::factory()->create(['name' => 'Carla Neri', 'company' => 'Forno Neri']);

    Livewire::test(GlobalSearch::class)->set('search', 'www.bellavista.example')
        ->assertSee('Pasticceria Bellavista')->assertSee('www.bellavista.example')->assertSee('Attivo')
        ->assertSee("/admin/websites/{$website->id}", escape: false);

    Livewire::test(GlobalSearch::class)->set('search', 'bellavista.it')
        ->assertSee('bellavista.it')->assertSee('30/09/2026')->assertSee('OVH')
        ->assertSee("/admin/domains/{$domain->id}", escape: false);

    Livewire::test(GlobalSearch::class)->set('search', 'Forno Neri')
        ->assertSee('Carla Neri')->assertSee('Forno Neri')->assertSee('Nuovo')
        ->assertSee("/admin/leads/{$lead->id}", escape: false);
});

it('finds an invoice by number and a quote by subject', function (): void {
    $client = Studio::client();
    $invoice = Studio::issued($client, [Studio::line('Sito web', '1000')]);
    Studio::draft($client, [Studio::line('Manutenzione', '50')]);
    $quote = Quote::factory()->create(['contact_id' => $client->id, 'customer_name' => $client->display_name, 'subject' => 'Restyling del sito']);

    Livewire::test(GlobalSearch::class)->set('search', '1/2026')
        ->assertSee('Fattura 1/2026')->assertSee('Pasticceria Bellavista S.r.l.')->assertSee('1.000,00')->assertSee('Emessa, da inviare')
        ->assertSee("/admin/invoices/{$invoice->id}", escape: false)
        ->assertDontSee('Fattura (bozza)');

    // The customer's name finds every document, the draft included, with a title that says it has no number yet.
    Livewire::test(GlobalSearch::class)->set('search', 'Pasticceria')->assertSee('Fattura 1/2026')->assertSee('Fattura (bozza)');

    Livewire::test(GlobalSearch::class)->set('search', 'Restyling')
        ->assertSee($quote->number_text)->assertSee('Restyling del sito')
        ->assertSee("/admin/quotes/{$quote->id}", escape: false);
});
