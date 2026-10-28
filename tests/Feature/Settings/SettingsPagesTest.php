<?php

declare(strict_types=1);

use App\Modules\Settings\Filament\Pages\BusinessProfilePage;
use App\Modules\Settings\Filament\Pages\InvoiceDefaultsPage;
use App\Modules\Settings\Filament\Pages\NumberingSeriesPage;
use App\Modules\Settings\Queries\GetBusinessProfile;
use App\Modules\Settings\Queries\GetInvoiceDefaults;
use App\Modules\Settings\Queries\GetNumberingSeries;
use Livewire\Livewire;

beforeEach(function (): void {
    signInAsOwner();
});

it('renders the settings pages', function (string $url): void {
    $this->get($url)->assertOk();
})->with(['/admin/settings/business-profile', '/admin/settings/invoices', '/admin/settings/numbering']);

it('saves the business profile from the form', function (): void {
    Livewire::test(BusinessProfilePage::class)
        ->fillForm([
            'type' => 'company',
            'business_name' => 'Studio Srl',
            'vat_number' => '01234567897',
            'regime' => 'RF01',
            'street' => 'Via Roma',
            'street_number' => '1',
            'postcode' => '00100',
            'city' => 'Roma',
            'province' => 'RM',
            'country' => 'IT',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $profile = app(GetBusinessProfile::class)->get();

    expect($profile->displayName())->toBe('Studio Srl')
        ->and($profile->vatNumber)->toBe('01234567897')
        ->and($profile->isComplete())->toBeTrue();
});

it('validates the profile form', function (): void {
    Livewire::test(BusinessProfilePage::class)
        ->fillForm(['type' => 'company', 'business_name' => '', 'vat_number' => '123', 'postcode' => 'abc'])
        ->call('save')
        ->assertHasFormErrors(['business_name', 'vat_number', 'postcode']);
});

it('saves invoice defaults from the form', function (): void {
    Livewire::test(InvoiceDefaultsPage::class)
        ->fillForm(['payment_terms_days' => 15, 'stamp_duty_charged_to_customer' => true, 'default_natura' => 'N4'])
        ->call('save')
        ->assertHasNoFormErrors();

    $defaults = app(GetInvoiceDefaults::class)->get();

    expect($defaults->paymentTermsDays)->toBe(15)
        ->and($defaults->stampDutyChargedToCustomer)->toBeTrue()
        ->and($defaults->defaultNatura?->value)->toBe('N4');
});

it('saves numbering series from the form, with opening numbers', function (): void {
    Livewire::test(NumberingSeriesPage::class)
        ->fillForm([
            'series' => [
                ['code' => 'invoices', 'label' => 'Fatture', 'format' => '{number:3}/{year}', 'opening_numbers' => [['year' => 2026, 'number' => 7]]],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $series = app(GetNumberingSeries::class)->get()->get('invoices');

    expect($series->format)->toBe('{number:3}/{year}')
        ->and($series->openingNumberFor(2026))->toBe(7);
});

it('rejects a number format without {number}', function (): void {
    Livewire::test(NumberingSeriesPage::class)
        ->fillForm(['series' => [['code' => 'invoices', 'label' => 'Fatture', 'format' => '{year}', 'opening_numbers' => []]]])
        ->call('save')
        ->assertHasFormErrors();
});

it('checks the owner\'s identifiers with the Kernel', function (): void {
    Livewire::test(BusinessProfilePage::class)
        ->fillForm(['type' => 'individual', 'first_name' => 'A', 'last_name' => 'B', 'regime' => 'RF19', 'country' => 'IT', 'vat_number' => '01234567890', 'tax_code' => 'RSSMRA85T10A562T'])
        ->call('save')
        ->assertHasFormErrors(['vat_number', 'tax_code']);
});
