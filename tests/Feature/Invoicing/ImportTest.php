<?php

declare(strict_types=1);

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Files\Queries\FilesOf;
use App\Modules\Invoicing\Actions\ExportXml;
use App\Modules\Invoicing\Actions\ImportFatturaPa;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\SupplierInvoice;
use App\Modules\Invoicing\Queries\SupplierInvoices;
use Tests\Feature\Invoicing\Studio;

/**
 * A minimal FPR12 file with the ns2 prefix real SdI files carry.
 *
 * @param  list<array{string, string, string, string}>  $lines  [description, quantity, unit price, rate]
 */
function fatturaPaXml(string $sellerVat, string $customerVat, string $numero, string $date, array $lines, string $taxable, string $vat, string $total): string
{
    $party = fn (string $vat, string $name): string => '<DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>'.$vat.'</IdCodice></IdFiscaleIVA>'
        .'<Anagrafica><Denominazione>'.$name.'</Denominazione></Anagrafica></DatiAnagrafici>'
        .'<Sede><Indirizzo>Via Verdi</Indirizzo><NumeroCivico>3</NumeroCivico><CAP>20100</CAP><Comune>Milano</Comune><Provincia>MI</Provincia><Nazione>IT</Nazione></Sede>';
    $body = '';
    foreach ($lines as $i => [$description, $quantity, $unit, $rate]) {
        $body .= '<DettaglioLinee><NumeroLinea>'.($i + 1).'</NumeroLinea><Descrizione>'.$description.'</Descrizione><Quantita>'.$quantity.'</Quantita>'
            .'<PrezzoUnitario>'.$unit.'</PrezzoUnitario><PrezzoTotale>0.00</PrezzoTotale><AliquotaIVA>'.$rate.'</AliquotaIVA></DettaglioLinee>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<ns2:FatturaElettronica versione="FPR12" xmlns:ns2="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">'
        .'<FatturaElettronicaHeader><CedentePrestatore>'.$party($sellerVat, 'Cedente Srl').'<DatiAnagrafici><RegimeFiscale>RF01</RegimeFiscale></DatiAnagrafici></CedentePrestatore>'
        .'<CessionarioCommittente>'.$party($customerVat, 'Cessionario Srl').'</CessionarioCommittente></FatturaElettronicaHeader>'
        .'<FatturaElettronicaBody><DatiGenerali><DatiGeneraliDocumento><TipoDocumento>TD01</TipoDocumento><Divisa>EUR</Divisa><Data>'.$date.'</Data><Numero>'.$numero.'</Numero>'
        .'<ImportoTotaleDocumento>'.$total.'</ImportoTotaleDocumento></DatiGeneraliDocumento></DatiGenerali>'
        .'<DatiBeniServizi>'.$body.'<DatiRiepilogo><AliquotaIVA>22.00</AliquotaIVA><ImponibileImporto>'.$taxable.'</ImponibileImporto><Imposta>'.$vat.'</Imposta></DatiRiepilogo></DatiBeniServizi>'
        .'<DatiPagamento><CondizioniPagamento>TP02</CondizioniPagamento><DettaglioPagamento><ModalitaPagamento>MP05</ModalitaPagamento><DataScadenzaPagamento>'.$date.'</DataScadenzaPagamento><ImportoPagamento>'.$total.'</ImportoPagamento></DettaglioPagamento></DatiPagamento>'
        .'</FatturaElettronicaBody></ns2:FatturaElettronica>';
}

beforeEach(function (): void {
    Studio::configure();
});

it('imports a supplier invoice, creates the supplier, stores the file, and skips it the second time', function (): void {
    $xml = fatturaPaXml('04827193048', '01234567897', '42', '2026-04-01', [['Dominio', '1.00', '12.00', '22.00']], '12.00', '2.64', '14.64');

    $result = app(ImportFatturaPa::class)->import('IT04827193048_0001A.xml', $xml);

    expect($result->status)->toBe('imported')->and($result->direction)->toBe('inbound');

    $supplierInvoice = SupplierInvoice::query()->sole();
    expect($supplierInvoice->number)->toBe('42')
        ->and($supplierInvoice->total_cents)->toBe(1_464)
        ->and($supplierInvoice->due_on?->toDateString())->toBe('2026-04-01')
        ->and($supplierInvoice->toData()->outstanding()->cents)->toBe(1_464)
        ->and(app(FilesOf::class)->get($supplierInvoice->toData()->reference(), 'xml'))->toHaveCount(1);

    $supplier = Contact::query()->findOrFail($supplierInvoice->contact_id);
    expect($supplier->vat_number)->toBe('04827193048')
        ->and($supplier->toData()->hasRole(ContactRole::Supplier))->toBeTrue()
        ->and($supplier->display_name)->toBe('Cedente Srl');

    expect(app(ImportFatturaPa::class)->import('again.xml', $xml)->status)->toBe('skipped')
        ->and(SupplierInvoice::query()->count())->toBe(1)
        ->and(app(SupplierInvoices::class)->payables())->toHaveCount(1);
});

it('imports one of the owner\'s own invoices issued elsewhere, so the numbering continues after it', function (): void {
    $existing = Contact::factory()->company('Existing')->withVatNumber('04827193048')->create();
    $xml = fatturaPaXml('01234567897', '04827193048', '7/2026', '2026-03-02', [['Restyling', '1.00', '1000.00', '22.00']], '1000.00', '220.00', '1220.00');

    $result = app(ImportFatturaPa::class)->import('IT01234567897_00007.xml', $xml);

    expect($result->status)->toBe('imported')->and($result->direction)->toBe('outbound');

    $invoice = Invoice::query()->sole();
    expect($invoice->status)->toBe(InvoiceStatus::SentManually)
        ->and($invoice->number)->toBe(7)
        ->and($invoice->number_text)->toBe('7/2026')
        ->and($invoice->total_cents)->toBe(122_000)
        ->and($invoice->contact_id)->toBe($existing->id)
        ->and($invoice->sdi_file_name)->toBe('IT01234567897_00007.xml')
        ->and(Contact::query()->count())->toBe(1);

    expect(app(ImportFatturaPa::class)->import('IT01234567897_00007.xml', $xml)->status)->toBe('skipped');

    $next = Studio::issued(Studio::client(['vat_number' => '00000000000', 'display_name' => 'Another', 'business_name' => 'Another']), [Studio::line('x', '10.00')], '2026-03-20');
    expect($next->numberText)->toBe('8/2026');
});

it('fails without writing anything on files that are not ours, unbalanced, or malformed', function (string $xml, string $reason): void {
    $result = app(ImportFatturaPa::class)->import('bad.xml', $xml);

    expect($result->status)->toBe('failed')
        ->and($result->reason)->toContain($reason)
        ->and(Invoice::query()->count() + SupplierInvoice::query()->count() + Contact::query()->count())->toBe(0);
})->with([
    'neither party is us' => [fatturaPaXml('11111111111', '22222222222', '1', '2026-01-01', [['X', '1.00', '10.00', '22.00']], '10.00', '2.20', '12.20'), 'profilo aziendale'],
    'declared total mismatch' => [fatturaPaXml('04827193048', '01234567897', '1', '2026-01-01', [['X', '1.00', '10.00', '22.00']], '10.00', '2.20', '99.00'), 'totale dichiarato'],
    'not XML' => ['this is not xml', 'XML'],
    'not an invoice' => ['<?xml version="1.0"?><root/>', 'fattura elettronica'],
]);

it('round-trips: an exported XML imports back to the same document', function (): void {
    $client = Studio::client();
    $invoice = Studio::issued($client, [Studio::line('Sito vetrina', '1800.00'), Studio::line('Ore extra', '37.50', 0, null, '3.5')], '2026-06-15');
    $export = app(ExportXml::class)->export($invoice->id);
    Invoice::query()->whereKey($invoice->id)->delete();

    $result = app(ImportFatturaPa::class)->import($export['filename'], $export['xml']);

    expect($result->status)->toBe('imported');
    $imported = Invoice::query()->sole();
    expect([$imported->year, $imported->number, $imported->number_text, $imported->total_cents])->toBe([2026, 1, '1/2026', 193_125])
        ->and($imported->contact_id)->toBe($client->id)
        ->and($imported->toData()->totals?->stampDutyDue)->toBeTrue();
});

it('reads the number out of common Numero formats', function (string $numero, int $expected): void {
    expect(ImportFatturaPa::numberFrom($numero))->toBe($expected);
})->with([['7/2026', 7], ['2026/007', 7], ['FT-0007', 7], ['12', 12]]);
