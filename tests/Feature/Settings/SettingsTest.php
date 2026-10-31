<?php

declare(strict_types=1);

use App\Kernel\Numbering\NumberSequence;
use App\Kernel\Vat\Natura;
use App\Modules\Audit\Queries\AuditTrail;
use App\Modules\Settings\Actions\SaveBusinessProfile;
use App\Modules\Settings\Actions\SaveInvoiceDefaults;
use App\Modules\Settings\Actions\SaveNumberingSeries;
use App\Modules\Settings\Data\BusinessProfile;
use App\Modules\Settings\Data\InvoiceDefaults;
use App\Modules\Settings\Data\NumberingSeries;
use App\Modules\Settings\Data\Series;
use App\Modules\Settings\Enums\ProfileType;
use App\Modules\Settings\Enums\RegimeFiscale;
use App\Modules\Settings\Queries\GetBusinessProfile;
use App\Modules\Settings\Queries\GetInvoiceDefaults;
use App\Modules\Settings\Queries\GetNumberingSeries;
use Illuminate\Support\Facades\DB;

it('starts from sensible defaults for a flat-rate freelancer', function (): void {
    $profile = app(GetBusinessProfile::class)->get();
    $defaults = app(GetInvoiceDefaults::class)->get();
    $series = app(GetNumberingSeries::class)->get();

    expect($profile->regime)->toBe(RegimeFiscale::Forfettario)
        ->and($profile->isComplete())->toBeFalse()
        ->and($defaults->paymentTermsDays)->toBe(30)
        ->and($defaults->defaultNatura)->toBe(Natura::N2_2)
        ->and($defaults->legalWording)->toContain('190/2014')
        ->and($defaults->invoiceSeries)->toBe('invoices')
        ->and($series->get('invoices')->render(3, 2026))->toBe('3/2026')
        ->and($series->get('invoices')->openingNumberFor(2026))->toBe(1);
});

it('saves the business profile and records it in the audit trail', function (): void {
    signInAsOwner();

    $profile = BusinessProfile::fromArray([
        'type' => 'individual',
        'first_name' => 'Mario',
        'last_name' => 'Rossi',
        'tax_code' => 'rssmra85t10a562s',
        'vat_number' => '01234567897',
        'regime' => 'RF19',
        'street' => 'Via Roma',
        'street_number' => '1',
        'postcode' => '00100',
        'city' => 'Roma',
        'province' => 'rm',
        'country' => 'it',
        'email' => 'mario@example.test',
    ]);

    app(SaveBusinessProfile::class)->save($profile);

    $stored = app(GetBusinessProfile::class)->get();

    expect($stored->displayName())->toBe('Mario Rossi')
        ->and($stored->taxCode)->toBe('RSSMRA85T10A562S')
        ->and($stored->province)->toBe('RM')
        ->and($stored->country)->toBe('IT')
        ->and($stored->isComplete())->toBeTrue()
        ->and($stored->toArray())->toBe($profile->toArray());

    $entry = app(AuditTrail::class)->latest(1)[0];
    expect($entry->action)->toBe('settings.business_profile.saved')
        ->and($entry->details['vat_number'])->toBe('01234567897');
});

it('names a company by its business name', function (): void {
    $profile = new BusinessProfile(type: ProfileType::Company, businessName: 'Studio Srl', firstName: 'ignored');

    expect($profile->displayName())->toBe('Studio Srl');
});

it('saves invoice defaults that point at existing series only', function (): void {
    app(SaveInvoiceDefaults::class)->save(new InvoiceDefaults(paymentTermsDays: 60, defaultNatura: null, inpsContributionEnabled: true));

    $defaults = app(GetInvoiceDefaults::class)->get();

    expect($defaults->paymentTermsDays)->toBe(60)
        ->and($defaults->defaultNatura)->toBeNull()
        ->and($defaults->inpsContributionEnabled)->toBeTrue()
        ->and($defaults->inpsContributionBasisPoints)->toBe(400);

    expect(fn () => app(SaveInvoiceDefaults::class)->save(new InvoiceDefaults(invoiceSeries: 'missing')))
        ->toThrow(InvalidArgumentException::class);
});

it('saves numbering series with opening numbers per year', function (): void {
    $series = new NumberingSeries([
        new Series('invoices', 'Fatture', '{number:4}/{year}', [2026 => 18]),
        new Series('credit-notes', 'Note di credito', 'NC{number}/{yy}'),
    ]);

    app(SaveNumberingSeries::class)->save($series);

    $stored = app(GetNumberingSeries::class)->get();

    expect($stored->get('invoices')->openingNumberFor(2026))->toBe(18)
        ->and($stored->get('invoices')->openingNumberFor(2027))->toBe(1)
        ->and($stored->get('invoices')->render(18, 2026))->toBe('0018/2026')
        ->and($stored->get('credit-notes')->render(2, 2026))->toBe('NC2/26')
        ->and($stored->find('nope'))->toBeNull();
});

it('locks an opening number once the app has issued a number in that series and year', function (): void {
    app(SaveNumberingSeries::class)->save(new NumberingSeries([new Series('invoices', 'Fatture', '{number}/{year}', [2026 => 10])]));
    DB::transaction(fn (): int => app(NumberSequence::class)->next('invoices', 2026, 10));

    $changed = new NumberingSeries([new Series('invoices', 'Fatture', '{number}/{year}', [2026 => 20, 2027 => 5])]);

    expect(app(SaveNumberingSeries::class)->lockedOpeningNumbers($changed))->toBe(['invoices' => [2026]])
        ->and(fn () => app(SaveNumberingSeries::class)->save($changed))->toThrow(LogicException::class);

    // The same opening number for 2026 and a new one for 2027 is fine.
    app(SaveNumberingSeries::class)->save(new NumberingSeries([new Series('invoices', 'Fatture', '{number}/{year}', [2026 => 10, 2027 => 5])]));

    expect(app(GetNumberingSeries::class)->get()->get('invoices')->openingNumberFor(2027))->toBe(5);
});

it('keeps the series the invoice defaults use', function (): void {
    expect(fn () => app(SaveNumberingSeries::class)->save(new NumberingSeries([new Series('other', 'Altro')])))
        ->toThrow(LogicException::class);
});

it('validates series definitions', function (): void {
    expect(fn () => new Series('Bad Code', 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Series('ok', 'x', 'no-number'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Series('ok', 'x', '{number}', [1999 => 1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new NumberingSeries([]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new NumberingSeries([new Series('a', 'a'), new Series('a', 'b')]))->toThrow(InvalidArgumentException::class);
});
