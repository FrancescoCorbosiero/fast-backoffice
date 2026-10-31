<?php

declare(strict_types=1);

namespace Tests\Feature\Invoicing;

use App\Kernel\Vat\Natura;
use App\Modules\Cash\Models\Account;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Invoicing\Actions\CreateDraft;
use App\Modules\Invoicing\Actions\IssueInvoice;
use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Settings\Actions\SaveBusinessProfile;
use App\Modules\Settings\Actions\SaveInvoiceDefaults;
use App\Modules\Settings\Data\BusinessProfile;
use App\Modules\Settings\Data\InvoiceDefaults;
use App\Modules\Settings\Enums\ProfileType;
use App\Modules\Settings\Enums\RegimeFiscale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * The studio the invoicing tests run as: a complete business profile,
 * invoice defaults, a client with a fiscal identity, a bank account.
 */
final class Studio
{
    public static function configure(RegimeFiscale $regime = RegimeFiscale::Forfettario, ?InvoiceDefaults $defaults = null): void
    {
        Storage::fake('files-local');

        app(SaveBusinessProfile::class)->save(new BusinessProfile(
            type: ProfileType::Individual,
            firstName: 'Mario',
            lastName: 'Rossi',
            taxCode: 'RSSMRA85T10A562S',
            vatNumber: '01234567897',
            regime: $regime,
            street: 'Via Roma',
            streetNumber: '1',
            postcode: '20100',
            city: 'Milano',
            province: 'MI',
            email: 'mario@studio.test',
            pec: 'mario@pec.test',
            phone: '+39 02 1234567',
            iban: 'IT60X0542811101000000123456',
        ));

        app(SaveInvoiceDefaults::class)->save($defaults ?? new InvoiceDefaults(
            inpsContributionEnabled: false,
            defaultNatura: $regime === RegimeFiscale::Forfettario ? Natura::N2_2 : null,
        ));
    }

    public static function client(array $overrides = []): Contact
    {
        return Contact::factory()->company('Pasticceria Bellavista S.r.l.')->withVatNumber('04827193048')->create([
            'street' => 'Via Manzoni',
            'street_number' => '12',
            'postcode' => '20121',
            'city' => 'Milano',
            'province' => 'MI',
            'sdi_code' => 'ABC1234',
            'pec' => null,
            ...$overrides,
        ]);
    }

    public static function account(): Account
    {
        return Account::factory()->create(['opened_on' => '2020-01-01']);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public static function draft(Contact $client, array $lines, int $terms = 30): InvoiceData
    {
        return app(CreateDraft::class)->create(DraftInput::fromArray(['contact_id' => $client->id, 'lines' => $lines, 'payment_terms_days' => $terms]));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public static function issued(Contact $client, array $lines, string $on = '2026-03-10', int $terms = 30): InvoiceData
    {
        return app(IssueInvoice::class)->issue(self::draft($client, $lines, $terms)->id, CarbonImmutable::parse($on));
    }

    /**
     * @return array<string, mixed>
     */
    public static function line(string $description, string $price, int $vatBasisPoints = 0, ?string $natura = null, string $quantity = '1'): array
    {
        return ['description' => $description, 'quantity' => $quantity, 'unit_price' => $price, 'vat_basis_points' => $vatBasisPoints, 'natura' => $natura];
    }
}
