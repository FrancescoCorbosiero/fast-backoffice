<?php

declare(strict_types=1);

use App\Modules\Cash\Models\Movement;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Modules\Invoicing\Filament\Resources\PaymentRequests\Pages\ViewPaymentRequest;
use App\Modules\Invoicing\Filament\Resources\RecurringInvoices\Pages\ListRecurringInvoices;
use App\Modules\Invoicing\Filament\Resources\SupplierInvoices\Pages\ListSupplierInvoices;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\RecurringInvoice;
use App\Modules\Invoicing\Models\SupplierInvoice;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Feature\Invoicing\Studio;

beforeEach(function (): void {
    Studio::configure();
    signInAsOwner();
    $this->travelTo('2026-03-15');
});

it('renders the invoicing screens', function (string $url): void {
    $this->get($url)->assertOk();
})->with(['/admin/invoices', '/admin/invoices/create', '/admin/payment-requests', '/admin/recurring-invoices', '/admin/supplier-invoices', '/admin/catalog', '/admin']);

it('creates a draft from the form, issues it, records a payment and marks it sent from the page', function (): void {
    $client = Studio::client();
    $account = Studio::account();

    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'contact_id' => $client->id,
            'payment_terms_days' => 30,
            'lines' => [['description' => 'Sviluppo sito', 'quantity' => '1', 'unit_price' => '1000', 'vat_percent' => '0', 'natura' => null]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->sole();
    expect($invoice->status)->toBe(InvoiceStatus::Draft);

    $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertSee('bozza');

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->callAction('issue', ['issued_on' => '2026-03-10'])
        ->assertNotified();
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Issued)->and($invoice->number_text)->toBe('1/2026');

    $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertSee('1/2026')->assertSee('Da inviare a SdI');
    $this->get("/admin/invoices/{$invoice->id}/edit")->assertForbidden();

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->callAction('payment', ['amount' => '1000', 'paid_on' => '2026-03-12', 'account_id' => $account->id])
        ->assertNotified();
    expect($invoice->refresh()->paid_cents)->toBe(100_000)->and(Movement::query()->count())->toBe(1);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->callAction('sent', ['sent_on' => '2026-03-13'])
        ->assertNotified();
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::SentManually);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->callAction('creditNote')->assertRedirect();
    expect(Invoice::query()->count())->toBe(2);
});

it('shows validation errors instead of issuing when the customer is incomplete', function (): void {
    $client = Studio::client(['vat_number' => null, 'tax_code' => null]);
    $draft = Studio::draft($client, [Studio::line('x', '10.00')]);

    Livewire::test(ViewInvoice::class, ['record' => $draft->id])
        ->callAction('issue', ['issued_on' => '2026-03-10'])
        ->assertNotified();

    expect(Invoice::query()->findOrFail($draft->id)->status)->toBe(InvoiceStatus::Draft);
});

it('pays and converts a payment request from its page', function (): void {
    $client = Studio::client();
    $account = Studio::account();
    $request = app(App\Modules\Invoicing\Actions\CreatePaymentRequest::class)->create(App\Modules\Invoicing\Data\DraftInput::fromArray(['contact_id' => $client->id, 'lines' => [Studio::line('Acconto', '300.00')]]));

    $this->get("/admin/payment-requests/{$request->id}")->assertOk()->assertSee('RP 1/2026');

    Livewire::test(ViewPaymentRequest::class, ['record' => $request->id])
        ->callAction('pay', ['amount' => '300', 'paid_on' => '2026-03-12', 'account_id' => $account->id])
        ->assertNotified()
        ->callAction('convert')
        ->assertNotified();

    expect(Invoice::query()->sole()->paid_cents)->toBe(30_000);
});

it('issues a recurring invoice from the list', function (): void {
    $schedule = RecurringInvoice::factory()->create(['contact_id' => Studio::client()->id, 'next_issue_on' => '2026-03-01']);

    Livewire::test(ListRecurringInvoices::class)->callTableAction('issue', $schedule)->assertNotified();

    expect(Invoice::query()->count())->toBe(1)->and($schedule->refresh()->next_issue_on->toDateString())->toBe('2026-04-01');
});

it('imports uploaded FatturaPA files from the supplier invoices list', function (): void {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><p:FatturaElettronica versione="FPR12" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2"><FatturaElettronicaHeader>'
        .'<CedentePrestatore><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>04827193048</IdCodice></IdFiscaleIVA><Anagrafica><Denominazione>Hosting Srl</Denominazione></Anagrafica><RegimeFiscale>RF01</RegimeFiscale></DatiAnagrafici><Sede><Indirizzo>Via A</Indirizzo><CAP>00100</CAP><Comune>Roma</Comune><Nazione>IT</Nazione></Sede></CedentePrestatore>'
        .'<CessionarioCommittente><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>01234567897</IdCodice></IdFiscaleIVA><Anagrafica><Denominazione>Mario Rossi</Denominazione></Anagrafica></DatiAnagrafici><Sede><Indirizzo>Via Roma</Indirizzo><CAP>20100</CAP><Comune>Milano</Comune><Nazione>IT</Nazione></Sede></CessionarioCommittente></FatturaElettronicaHeader>'
        .'<FatturaElettronicaBody><DatiGenerali><DatiGeneraliDocumento><TipoDocumento>TD01</TipoDocumento><Divisa>EUR</Divisa><Data>2026-02-01</Data><Numero>99</Numero><ImportoTotaleDocumento>61.00</ImportoTotaleDocumento></DatiGeneraliDocumento></DatiGenerali>'
        .'<DatiBeniServizi><DettaglioLinee><NumeroLinea>1</NumeroLinea><Descrizione>Hosting</Descrizione><PrezzoUnitario>50.00</PrezzoUnitario><PrezzoTotale>50.00</PrezzoTotale><AliquotaIVA>22.00</AliquotaIVA></DettaglioLinee>'
        .'<DatiRiepilogo><AliquotaIVA>22.00</AliquotaIVA><ImponibileImporto>50.00</ImponibileImporto><Imposta>11.00</Imposta></DatiRiepilogo></DatiBeniServizi></FatturaElettronicaBody></p:FatturaElettronica>';

    Livewire::test(ListSupplierInvoices::class)
        ->callAction('import', ['files' => [UploadedFile::fake()->createWithContent('IT04827193048_00099.xml', $xml)]])
        ->assertNotified();

    $imported = SupplierInvoice::query()->sole();
    expect($imported->total_cents)->toBe(6_100)->and($imported->supplier_name)->toBe('Hosting Srl');
    $this->get("/admin/supplier-invoices/{$imported->id}")->assertOk()->assertSee('Hosting Srl');
});

it('shows the invoices on the contact page and the overview on the dashboard', function (): void {
    $client = Studio::client();
    Studio::issued($client, [Studio::line('Sito', '1000.00')], '2026-03-10');

    $this->get("/admin/contacts/{$client->id}")->assertOk()->assertSee('Fatture');
    Livewire::test(App\Modules\Invoicing\Filament\Widgets\ContactInvoicesTable::class, ['contactId' => $client->id])->assertSee('1/2026');
    $this->get('/admin')->assertOk();
    Livewire::test(App\Modules\Invoicing\Filament\Widgets\InvoicingOverviewWidget::class)->assertSee('Fatture da inviare a SdI')->assertSee('la prima scade tra 7 giorni');
});
