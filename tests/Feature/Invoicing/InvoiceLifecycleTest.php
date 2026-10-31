<?php

declare(strict_types=1);

use App\Kernel\Numbering\NumberSequence;
use App\Modules\Audit\Queries\AuditTrail;
use App\Modules\Files\Queries\FilesOf;
use App\Modules\Files\Queries\ReadFile;
use App\Modules\Invoicing\Actions\CreateCreditNote;
use App\Modules\Invoicing\Actions\DeleteDraft;
use App\Modules\Invoicing\Actions\ExportXml;
use App\Modules\Invoicing\Actions\IssueInvoice;
use App\Modules\Invoicing\Actions\MarkSentManually;
use App\Modules\Invoicing\Actions\UpdateDraft;
use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Invoicing\Enums\DocumentType;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Events\InvoiceIssued;
use App\Modules\Invoicing\Integrations\FatturaPa\FatturaPaValidator;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Settings\Actions\SaveNumberingSeries;
use App\Modules\Settings\Data\NumberingSeries;
use App\Modules\Settings\Data\Series;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Invoicing\Studio;

function xpath(string $xml): DOMXPath
{
    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();

    return new DOMXPath($dom);
}

beforeEach(function (): void {
    Studio::configure();
});

it('starts as a draft without a number, editable and deletable', function (): void {
    $client = Studio::client();
    $draft = Studio::draft($client, [Studio::line('Sviluppo sito', '1000.00')]);

    expect($draft->status)->toBe(InvoiceStatus::Draft)
        ->and($draft->number)->toBeNull()
        ->and($draft->numberText)->toBeNull()
        ->and($draft->customerName)->toBe('Pasticceria Bellavista S.r.l.')
        ->and($draft->title())->toBe('Fattura (bozza)')
        ->and($draft->total->isZero())->toBeTrue();

    $updated = app(UpdateDraft::class)->update($draft->id, DraftInput::fromArray(['contact_id' => $client->id, 'lines' => [Studio::line('Altro', '50.00')], 'notes' => 'n']));
    expect($updated->lines[0]->description)->toBe('Altro')->and($updated->notes)->toBe('n');

    app(DeleteDraft::class)->delete($draft->id);
    expect(Invoice::query()->count())->toBe(0);
});

it('refuses a draft without lines or without a customer', function (): void {
    $client = Studio::client();

    expect(fn () => Studio::draft($client, []))->toThrow(ValidationException::class)
        ->and(fn () => app(App\Modules\Invoicing\Actions\CreateDraft::class)->create(DraftInput::fromArray(['contact_id' => 999, 'lines' => [Studio::line('x', '1')]])))->toThrow(ValidationException::class)
        ->and(fn () => Studio::draft($client, [Studio::line('', '1')]))->toThrow(ValidationException::class);
});

it('issues: allocates the number, freezes snapshots and totals, stores a valid XML and the PDF', function (): void {
    Event::fake([InvoiceIssued::class]);
    $client = Studio::client();
    $this->travelTo('2026-03-12');

    $invoice = Studio::issued($client, [Studio::line('Sviluppo sito', '1000.00')], '2026-03-10');

    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->numberText)->toBe('1/2026')
        ->and($invoice->issuedOn?->toDateString())->toBe('2026-03-10')
        ->and($invoice->dueOn?->toDateString())->toBe('2026-04-09')
        ->and($invoice->total->cents)->toBe(100_000)
        ->and($invoice->totals?->stampDutyDue)->toBeTrue()
        ->and($invoice->totals?->stampDutyCharged->cents)->toBe(0)
        ->and($invoice->seller?->name)->toBe('Mario Rossi')
        ->and($invoice->customer?->vatNumber?->number)->toBe('04827193048')
        ->and($invoice->regime)->toBe('RF19')
        ->and($invoice->sdiFileName)->toBe('IT01234567897_00001.xml')
        ->and($invoice->daysLeftToSend(CarbonImmutable::parse('2026-03-12')))->toBe(10)
        ->and($invoice->lines[0]->natura?->value)->toBe('N2.2');

    $files = app(FilesOf::class)->get($invoice->reference());
    expect(array_map(fn ($f) => $f->kind, $files))->toEqualCanonicalizing(['xml', 'pdf']);

    $export = app(ExportXml::class)->export($invoice->id);
    expect($export['filename'])->toBe('IT01234567897_00001.xml')
        ->and(app(FatturaPaValidator::class)->errors($export['xml']))->toBe([]);

    $xp = xpath($export['xml']);
    expect($xp->evaluate('string(//ProgressivoInvio)'))->toBe('00001')
        ->and($xp->evaluate('string(//CodiceDestinatario)'))->toBe('ABC1234')
        ->and($xp->evaluate('string(//CedentePrestatore//RegimeFiscale)'))->toBe('RF19')
        ->and($xp->evaluate('string(//CedentePrestatore//Nome)'))->toBe('Mario')
        ->and($xp->evaluate('string(//CessionarioCommittente//Denominazione)'))->toBe('Pasticceria Bellavista S.r.l.')
        ->and($xp->evaluate('string(//Numero)'))->toBe('1/2026')
        ->and($xp->evaluate('string(//DatiBollo/ImportoBollo)'))->toBe('2.00')
        ->and($xp->evaluate('string(//DatiRiepilogo/Natura)'))->toBe('N2.2')
        ->and($xp->evaluate('string(//ImportoTotaleDocumento)'))->toBe('1000.00')
        ->and($xp->evaluate('string(//DettaglioLinee/PrezzoTotale)'))->toBe('1000.00')
        ->and($xp->evaluate('string(//Causale)'))->toContain('190/2014')
        ->and($xp->evaluate('string(//DatiPagamento//ImportoPagamento)'))->toBe('1000.00')
        ->and($xp->evaluate('string(//DatiPagamento//IBAN)'))->toBe('IT60X0542811101000000123456');

    $pdf = app(ReadFile::class)->contents($invoice->pdfFileId ?? 0);
    expect(substr($pdf, 0, 4))->toBe('%PDF');

    expect(app(AuditTrail::class)->forSubject($invoice->reference())[0]->action)->toBe('invoicing.invoice.issued');
    Event::assertDispatched(InvoiceIssued::class);

    $second = Studio::issued($client, [Studio::line('Altro', '10.00')], '2026-03-11');
    expect($second->numberText)->toBe('2/2026')->and($second->sdiFileName)->toBe('IT01234567897_00002.xml')
        ->and($second->totals?->stampDutyDue)->toBeFalse();
});

it('never changes an issued document', function (): void {
    $client = Studio::client();
    $invoice = Studio::issued($client, [Studio::line('x', '10.00')]);

    expect(fn () => app(UpdateDraft::class)->update($invoice->id, DraftInput::fromArray(['contact_id' => $client->id, 'lines' => [Studio::line('y', '1')]])))->toThrow(LogicException::class)
        ->and(fn () => app(DeleteDraft::class)->delete($invoice->id))->toThrow(LogicException::class)
        ->and(fn () => app(IssueInvoice::class)->issue($invoice->id))->toThrow(LogicException::class);
});

it('releases the number when issuing fails, so there is no gap', function (): void {
    $client = Studio::client();
    $incomplete = Studio::client(['vat_number' => null, 'tax_code' => null, 'display_name' => 'Nobody', 'business_name' => 'Nobody']);
    Studio::issued($client, [Studio::line('x', '10.00')]);

    expect(fn () => Studio::issued($incomplete, [Studio::line('x', '10.00')]))->toThrow(ValidationException::class);

    expect(Studio::issued($client, [Studio::line('y', '10.00')])->numberText)->toBe('2/2026')
        ->and(app(NumberSequence::class)->last(IssueInvoice::PROGRESSIVO_SERIES, 0))->toBe(2);
});

it('refuses to issue in the future, without a complete profile, or with a bad natura', function (): void {
    $client = Studio::client();
    $draft = Studio::draft($client, [Studio::line('x', '10.00')]);

    expect(fn () => app(IssueInvoice::class)->issue($draft->id, CarbonImmutable::today()->addDay()))->toThrow(ValidationException::class);

    app(App\Modules\Settings\Actions\SaveInvoiceDefaults::class)->save(new App\Modules\Settings\Data\InvoiceDefaults(defaultNatura: null));
    expect(fn () => app(IssueInvoice::class)->issue($draft->id))->toThrow(ValidationException::class);

    app(App\Modules\Settings\Actions\SaveBusinessProfile::class)->save(new App\Modules\Settings\Data\BusinessProfile);
    expect(fn () => app(IssueInvoice::class)->issue($draft->id))->toThrow(ValidationException::class);
});

it('starts the year from the configured opening number and renders the configured format', function (): void {
    app(SaveNumberingSeries::class)->save(new NumberingSeries([new Series('invoices', 'Fatture', '{number:3}/{year}', [2026 => 18])]));
    $client = Studio::client();

    expect(Studio::issued($client, [Studio::line('x', '10.00')])->numberText)->toBe('018/2026')
        ->and(Studio::issued($client, [Studio::line('x', '10.00')])->numberText)->toBe('019/2026');
});

it('marks an invoice as sent manually, once', function (): void {
    $client = Studio::client();
    $invoice = Studio::issued($client, [Studio::line('x', '10.00')]);

    $sent = app(MarkSentManually::class)->mark($invoice->id, CarbonImmutable::parse('2026-03-15'));

    expect($sent->status)->toBe(InvoiceStatus::SentManually)
        ->and($sent->sentChannel?->value)->toBe('manual')
        ->and($sent->sentAt?->toDateString())->toBe('2026-03-15')
        ->and($sent->daysLeftToSend(CarbonImmutable::today()))->toBeNull()
        ->and(fn () => app(MarkSentManually::class)->mark($invoice->id))->toThrow(LogicException::class);
});

it('corrects an issued invoice with a credit note in the same series', function (): void {
    $client = Studio::client();
    $invoice = Studio::issued($client, [Studio::line('Sito', '500.00'), Studio::line('Hosting', '100.00')]);

    $note = app(CreateCreditNote::class)->create($invoice->id);
    expect($note->type)->toBe(DocumentType::CreditNote)
        ->and($note->relatedInvoiceId)->toBe($invoice->id)
        ->and($note->lines)->toHaveCount(2)
        ->and($note->status)->toBe(InvoiceStatus::Draft);

    $issued = app(IssueInvoice::class)->issue($note->id, CarbonImmutable::parse('2026-03-20'));
    expect($issued->numberText)->toBe('2/2026')->and($issued->dueOn)->toBeNull()->and($issued->total->cents)->toBe(60_000);

    $xp = xpath(app(ExportXml::class)->export($issued->id)['xml']);
    expect($xp->evaluate('string(//TipoDocumento)'))->toBe('TD04')
        ->and($xp->evaluate('string(//DatiFattureCollegate/IdDocumento)'))->toBe('1/2026')
        ->and($xp->evaluate('string(//DatiFattureCollegate/Data)'))->toBe('2026-03-10');

    expect(fn () => app(CreateCreditNote::class)->create($issued->id))->toThrow(ValidationException::class);
});

it('counts the quarter\'s stamp duty', function (): void {
    $client = Studio::client();
    Studio::issued($client, [Studio::line('a', '100.00')], '2026-01-15');
    Studio::issued($client, [Studio::line('b', '50.00')], '2026-02-15');
    Studio::issued($client, [Studio::line('c', '100.00')], '2026-04-15');

    $quarter = app(App\Modules\Invoicing\Queries\Invoices::class)->stampDutyQuarter(2026, 1);
    expect($quarter['count'])->toBe(1)->and($quarter['total']->cents)->toBe(200);
});

it('keeps the number unique per series and year at the database level', function (): void {
    $client = Studio::client();
    Studio::issued($client, [Studio::line('a', '1.00')]);

    expect(fn () => DB::table('invoices')->where('number', 1)->update(['number' => 1]))->not->toThrow(Exception::class);
    expect(fn () => Invoice::factory()->create(['series' => 'invoices', 'year' => 2026, 'number' => 1, 'status' => InvoiceStatus::Issued, 'contact_id' => $client->id]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
