<?php

declare(strict_types=1);

use App\Kernel\Vat\Natura;
use App\Modules\Invoicing\Actions\ExportXml;
use App\Modules\Invoicing\Integrations\FatturaPa\FatturaPaValidator;
use App\Modules\Settings\Data\InvoiceDefaults;
use App\Modules\Settings\Enums\RegimeFiscale;
use Tests\Feature\Invoicing\Studio;

function xmlOf(int $invoiceId): DOMXPath
{
    $xml = app(ExportXml::class)->export($invoiceId)['xml'];
    expect(app(FatturaPaValidator::class)->errors($xml))->toBe([]);
    $dom = new DOMDocument;
    $dom->loadXML($xml);

    return new DOMXPath($dom);
}

it('computes VAT per rate on the summed taxable amount, the way SdI checks it', function (): void {
    Studio::configure(RegimeFiscale::Ordinario, new InvoiceDefaults(defaultNatura: null));
    $client = Studio::client();
    $lines = array_fill(0, 10, Studio::line('Micro', '0.10', 2200));

    $invoice = Studio::issued($client, $lines);
    $xp = xmlOf($invoice->id);

    expect($invoice->totals?->vat->cents)->toBe(22)
        ->and($invoice->total->cents)->toBe(122)
        ->and($xp->evaluate('count(//DettaglioLinee)'))->toBe(10.0)
        ->and($xp->evaluate('count(//DatiRiepilogo)'))->toBe(1.0)
        ->and($xp->evaluate('string(//DatiRiepilogo/ImponibileImporto)'))->toBe('1.00')
        ->and($xp->evaluate('string(//DatiRiepilogo/Imposta)'))->toBe('0.22')
        ->and($xp->evaluate('string(//DatiRiepilogo/AliquotaIVA)'))->toBe('22.00')
        ->and($xp->evaluate('string(//ImportoTotaleDocumento)'))->toBe('1.22')
        ->and($xp->evaluate('count(//DatiBollo)'))->toBe(0.0);
});

it('groups the summary by rate and natura and applies the INPS contribution to the main group', function (): void {
    Studio::configure(RegimeFiscale::Ordinario, new InvoiceDefaults(defaultNatura: Natura::N4, inpsContributionEnabled: true, inpsContributionBasisPoints: 400));
    $client = Studio::client();

    $invoice = Studio::issued($client, [
        Studio::line('Consulenza', '1000.00', 2200),
        Studio::line('Ore', '100.00', 2200, null, '2.5'),
        Studio::line('Esente', '100.00', 0),
    ]);
    $totals = $invoice->totals;
    $xp = xmlOf($invoice->id);

    // Lines 1250 + 100; INPS 4% of 1350 = 54 on the 22% group: taxable 1304 → VAT 286.88.
    expect($totals?->subtotal->cents)->toBe(135_000)
        ->and($totals?->inps->cents)->toBe(5_400)
        ->and($totals?->inpsVatRate?->basisPoints)->toBe(2200)
        ->and($totals?->vat->cents)->toBe(28_688)
        ->and($invoice->total->cents)->toBe(135_000 + 5_400 + 28_688)
        ->and($xp->evaluate('string(//DatiCassaPrevidenziale/TipoCassa)'))->toBe('TC22')
        ->and($xp->evaluate('string(//DatiCassaPrevidenziale/AlCassa)'))->toBe('4.00')
        ->and($xp->evaluate('string(//DatiCassaPrevidenziale/ImportoContributoCassa)'))->toBe('54.00')
        ->and($xp->evaluate('string(//DatiCassaPrevidenziale/ImponibileCassa)'))->toBe('1350.00')
        ->and($xp->evaluate('string(//DatiCassaPrevidenziale/AliquotaIVA)'))->toBe('22.00')
        ->and($xp->evaluate('count(//DatiRiepilogo)'))->toBe(2.0)
        ->and($xp->evaluate('string(//DatiRiepilogo[1]/ImponibileImporto)'))->toBe('1304.00')
        ->and($xp->evaluate('string(//DatiRiepilogo[2]/Natura)'))->toBe('N4')
        ->and($xp->evaluate('string(//DettaglioLinee[2]/Quantita)'))->toBe('2.5000')
        ->and($xp->evaluate('string(//DettaglioLinee[2]/PrezzoTotale)'))->toBe('250.00');
});

it('charges the stamp duty to the customer as a line excluded from VAT when the setting says so', function (): void {
    Studio::configure(RegimeFiscale::Forfettario, new InvoiceDefaults(defaultNatura: Natura::N2_2, stampDutyChargedToCustomer: true));
    $client = Studio::client();

    $invoice = Studio::issued($client, [Studio::line('Sito', '500.00')]);
    $xp = xmlOf($invoice->id);

    expect($invoice->totals?->stampDutyDue)->toBeTrue()
        ->and($invoice->totals?->stampDutyCharged->cents)->toBe(200)
        ->and($invoice->total->cents)->toBe(50_200)
        ->and($xp->evaluate('count(//DettaglioLinee)'))->toBe(2.0)
        ->and($xp->evaluate('string(//DettaglioLinee[2]/Natura)'))->toBe('N1')
        ->and($xp->evaluate('string(//DettaglioLinee[2]/PrezzoTotale)'))->toBe('2.00')
        ->and($xp->evaluate('string(//DatiBollo/BolloVirtuale)'))->toBe('SI')
        ->and($xp->evaluate('string(//ImportoTotaleDocumento)'))->toBe('502.00')
        ->and($xp->evaluate('count(//DatiRiepilogo)'))->toBe(2.0);
});

it('owes no stamp duty on exempt amounts up to €77.47, on exports, or on taxed invoices', function (): void {
    Studio::configure(RegimeFiscale::Forfettario);
    $client = Studio::client();

    expect(Studio::issued($client, [Studio::line('Piccola', '77.47')])->totals?->stampDutyDue)->toBeFalse()
        ->and(Studio::issued($client, [Studio::line('Appena sopra', '77.48')])->totals?->stampDutyDue)->toBeTrue()
        ->and(Studio::issued($client, [Studio::line('Export', '1000.00', 0, 'N3.1')])->totals?->stampDutyDue)->toBeFalse();
});

it('refuses VAT on lines in the flat-rate regime', function (): void {
    Studio::configure(RegimeFiscale::Forfettario);
    $client = Studio::client();

    expect(fn () => Studio::issued($client, [Studio::line('Con IVA', '100.00', 2200)]))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('addresses a customer abroad with its country, 00000 as postcode and XXXXXXX as destination', function (): void {
    Studio::configure(RegimeFiscale::Ordinario, new InvoiceDefaults(defaultNatura: Natura::N2_1));
    $client = Studio::client(['vat_country' => 'DE', 'vat_number' => '123456789', 'country' => 'DE', 'province' => null, 'postcode' => '10115', 'city' => 'Berlin', 'sdi_code' => null, 'tax_code' => null]);

    $invoice = Studio::issued($client, [Studio::line('Consulenza UE', '1000.00', 0, 'N2.1')]);
    $xp = xmlOf($invoice->id);

    expect($xp->evaluate('string(//CodiceDestinatario)'))->toBe('XXXXXXX')
        ->and($xp->evaluate('string(//CessionarioCommittente//IdPaese)'))->toBe('DE')
        ->and($xp->evaluate('string(//CessionarioCommittente//IdCodice)'))->toBe('123456789')
        ->and($xp->evaluate('string(//CessionarioCommittente/Sede/CAP)'))->toBe('00000')
        ->and($xp->evaluate('count(//CessionarioCommittente/Sede/Provincia)'))->toBe(0.0)
        ->and($xp->evaluate('string(//CessionarioCommittente/Sede/Nazione)'))->toBe('DE')
        ->and($invoice->totals?->stampDutyDue)->toBeTrue();
});

it('addresses a consumer by tax code, through the portal, or by PEC when they gave one', function (): void {
    Studio::configure();
    $consumer = App\Modules\Contacts\Models\Contact::factory()->withTaxCode('MRTMTT91D08F205J')->create(['sdi_code' => null, 'pec' => null, 'street' => 'Via Verdi', 'postcode' => '00100', 'city' => 'Roma', 'province' => 'RM']);
    $withPec = Studio::client(['vat_number' => '00000000000', 'sdi_code' => null, 'pec' => 'cliente@pec.test']);

    $xp = xmlOf(Studio::issued($consumer, [Studio::line('Sito', '300.00')])->id);
    expect($xp->evaluate('string(//CodiceDestinatario)'))->toBe('0000000')
        ->and($xp->evaluate('count(//PECDestinatario)'))->toBe(0.0)
        ->and($xp->evaluate('count(//CessionarioCommittente//IdFiscaleIVA)'))->toBe(0.0)
        ->and($xp->evaluate('string(//CessionarioCommittente//CodiceFiscale)'))->toBe('MRTMTT91D08F205J')
        ->and($xp->evaluate('string(//CessionarioCommittente//Nome)'))->not->toBe('');

    $xp = xmlOf(Studio::issued($withPec, [Studio::line('Sito', '300.00')])->id);
    expect($xp->evaluate('string(//CodiceDestinatario)'))->toBe('0000000')
        ->and($xp->evaluate('string(//PECDestinatario)'))->toBe('cliente@pec.test');
});

it('matches the golden files, each valid against the official schema', function (string $name, Closure $setup): void {
    $invoiceId = $setup();
    $xml = app(ExportXml::class)->export($invoiceId)['xml'];
    $path = base_path("tests/Fixtures/fatturapa/{$name}.xml");

    expect(app(FatturaPaValidator::class)->errors($xml))->toBe([]);

    if (getenv('UPDATE_FATTURAPA_GOLDEN') === '1') {
        file_put_contents($path, $xml);
    }

    expect(file_exists($path))->toBeTrue("Missing golden file {$path}: run with UPDATE_FATTURAPA_GOLDEN=1 and review it.");
    expect($xml)->toBe((string) file_get_contents($path));
})->with([
    'flat-rate invoice with stamp duty' => ['forfettario-td01', function (): int {
        Studio::configure();

        return Studio::issued(Studio::client(), [Studio::line('Sviluppo sito web', '1500.00'), Studio::line('Hosting annuale', '120.00')], '2026-03-10')->id;
    }],
    'ordinary invoice with INPS' => ['ordinario-inps-td01', function (): int {
        Studio::configure(RegimeFiscale::Ordinario, new InvoiceDefaults(defaultNatura: null, inpsContributionEnabled: true));

        return Studio::issued(Studio::client(), [Studio::line('Consulenza', '1000.00', 2200), Studio::line('Ore extra', '37.50', 2200, null, '3.5')], '2026-06-15')->id;
    }],
    'credit note' => ['forfettario-td04', function (): int {
        Studio::configure();
        $client = Studio::client();
        $invoice = Studio::issued($client, [Studio::line('Sviluppo sito web', '1500.00')], '2026-03-10');
        $note = app(App\Modules\Invoicing\Actions\CreateCreditNote::class)->create($invoice->id);

        return app(App\Modules\Invoicing\Actions\IssueInvoice::class)->issue($note->id, Carbon\CarbonImmutable::parse('2026-03-20'))->id;
    }],
    'customer abroad' => ['estero-td01', function (): int {
        Studio::configure(RegimeFiscale::Ordinario, new InvoiceDefaults(defaultNatura: Natura::N2_1));
        $client = Studio::client(['vat_country' => 'DE', 'vat_number' => '123456789', 'country' => 'DE', 'province' => null, 'postcode' => '10115', 'city' => 'Berlin', 'sdi_code' => null, 'tax_code' => null, 'business_name' => 'Beispiel GmbH', 'display_name' => 'Beispiel GmbH']);

        return Studio::issued($client, [Studio::line('Consulenza', '1000.00', 0, 'N2.1')], '2026-05-05')->id;
    }],
]);
