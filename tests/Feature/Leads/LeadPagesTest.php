<?php

declare(strict_types=1);

use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Filament\Components\LeadLink;
use App\Modules\Leads\Filament\Resources\Leads\LeadResource;
use App\Modules\Leads\Filament\Resources\Leads\Pages\CreateLead;
use App\Modules\Leads\Filament\Resources\Leads\Pages\EditLead;
use App\Modules\Leads\Filament\Resources\Leads\Pages\ListLeads;
use App\Modules\Leads\Filament\Resources\Leads\Pages\ViewLead;
use App\Modules\Leads\Filament\Widgets\LeadsPipelineWidget;
use App\Modules\Leads\Models\Lead;
use App\Modules\Quotes\Models\Quote;
use App\Modules\Websites\Filament\Components\WebsiteLink;
use Livewire\Livewire;
use Tests\Feature\Invoicing\Studio;

beforeEach(function (): void {
    Studio::configure();
    signInAsOwner();
    $this->travelTo('2026-03-15 10:00:00');
});

it('renders the lead screens', function (string $url): void {
    $this->get($url)->assertOk();
})->with(['/admin/leads', '/admin/leads/create']);

it('creates, edits, moves and converts a lead from the panel', function (): void {
    Livewire::test(CreateLead::class)
        ->fillForm(['name' => 'Giulia Verdi', 'company' => 'Fioreria Verdi', 'email' => 'giulia@verdi.example', 'estimated_value' => '1500', 'next_follow_up_on' => '2026-03-10'])
        ->call('create')
        ->assertHasNoFormErrors();
    $lead = Lead::query()->sole();
    expect($lead->estimated_value_cents)->toBe(150_000);

    $this->get("/admin/leads/{$lead->id}")->assertOk()->assertSee('Fioreria Verdi');
    // The one overdue lead: the dashboard stat opens it directly.
    Livewire::test(LeadsPipelineWidget::class)->assertSee(LeadResource::getUrl('view', ['record' => $lead->id]), escape: false)->assertSee(LeadResource::getUrl('index'), escape: false);

    Livewire::test(EditLead::class, ['record' => $lead->id])->assertSchemaStateSet(['estimated_value' => '1500.00'])->fillForm(['source' => 'fiera'])->call('save')->assertHasNoFormErrors();
    expect($lead->refresh()->source)->toBe('fiera');

    Livewire::test(ViewLead::class, ['record' => $lead->id])->callAction('move', ['status' => 'contacted', 'next_follow_up_on' => '2026-03-20'])->assertNotified();
    expect($lead->refresh()->status)->toBe(LeadStatus::Contacted);

    Livewire::test(ListLeads::class)->assertCanSeeTableRecords([$lead])->filterTable('due')->assertCanNotSeeTableRecords([$lead]);
    Livewire::test(LeadsPipelineWidget::class)->assertSee('Lead aperti')->assertSee('filters%5B', escape: false);

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('convert', ['existing_contact_id' => null, 'as_company' => true, 'with_quote' => false, 'with_website' => true, 'website_name' => 'Fioreria Verdi', 'website_url' => ''])
        ->assertHasActionErrors(['website_url' => 'required']);

    $contactUrl = fn (): string => ContactLink::url(Contact::query()->where('display_name', 'Fioreria Verdi')->sole()->id);
    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('convert', [
            'existing_contact_id' => null, 'as_company' => true,
            'with_quote' => true, 'quote_subject' => 'Sito nuovo', 'lines' => [['description' => 'Sito vetrina', 'quantity' => '1', 'unit_price' => '1500', 'vat_percent' => '0', 'natura' => null]],
            'with_website' => true, 'website_name' => 'Fioreria Verdi', 'website_url' => 'https://www.fioreriaverdi.example',
        ])
        ->assertNotified()
        ->assertRedirect($contactUrl());

    $lead->refresh();
    expect($lead->status)->toBe(LeadStatus::Won)
        ->and(Contact::query()->where('display_name', 'Fioreria Verdi')->exists())->toBeTrue()
        ->and(Quote::query()->count())->toBe(1)
        ->and($lead->website_id)->not->toBeNull();
    $this->get("/admin/leads/{$lead->id}")->assertOk()->assertSee('Vinto')
        ->assertSee($contactUrl(), escape: false)->assertSee('Sito nuovo')->assertSee(WebsiteLink::url((int) $lead->website_id), escape: false);
    $this->get($contactUrl())->assertOk()->assertSee('Da lead')->assertSee(LeadLink::url($lead->id), escape: false);
    $this->get("/admin/leads/{$lead->id}/edit")->assertForbidden();
});

it('works the pipeline from the list and starts a lead from a link that already knows the prospect', function (): void {
    $lead = Lead::factory()->create();
    $other = Lead::factory()->create(['name' => 'Marco Neri', 'company' => null, 'email' => 'marco@neri.example']);

    Livewire::test(ListLeads::class)
        ->callTableAction('move', $lead, ['status' => 'qualified', 'next_follow_up_on' => '2026-03-20'])->assertNotified()
        ->callTableBulkAction('move', [$lead, $other], ['status' => 'contacted'])->assertNotified()
        ->filterTable('closed')->assertCanNotSeeTableRecords([$lead, $other]);
    expect($lead->refresh()->status)->toBe(LeadStatus::Contacted)->and($other->refresh()->status)->toBe(LeadStatus::Contacted);

    $url = LeadLink::createUrl(['name' => 'Anna Bruni', 'email' => 'anna@bruni.example', 'source' => 'cal.com', 'company' => null]);
    expect($url)->toContain('/admin/leads/create?')->toContain('source=cal.com')->not->toContain('company');
    Livewire::withQueryParams(['name' => 'Anna Bruni', 'email' => 'anna@bruni.example', 'source' => 'cal.com'])
        ->test(CreateLead::class)
        ->assertSchemaStateSet(['name' => 'Anna Bruni', 'email' => 'anna@bruni.example', 'source' => 'cal.com']);
});
