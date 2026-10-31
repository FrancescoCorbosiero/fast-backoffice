<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Kernel\Numbering\NumberSequence;
use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Files\Actions\StoreFile;
use App\Modules\Invoicing\Data\FatturaPaDocument;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Data\PartySnapshot;
use App\Modules\Invoicing\DocumentContext;
use App\Modules\Invoicing\Enums\DocumentType;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Events\InvoiceIssued;
use App\Modules\Invoicing\Integrations\FatturaPa\FatturaPaValidator;
use App\Modules\Invoicing\Integrations\FatturaPa\FatturaPaWriter;
use App\Modules\Invoicing\Integrations\Pdf\CourtesyPdfRenderer;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Settings\Enums\RegimeFiscale;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Issue (design.md §5.3), in one transaction: allocate the number, freeze
 * the seller and customer snapshots and the totals, build and validate
 * the XML, store the XML and the courtesy PDF. From here on the content
 * is immutable. A failure anywhere releases the number.
 */
final readonly class IssueInvoice
{
    public const string PROGRESSIVO_SERIES = 'sdi-progressivo';

    /** Days to resend a rejected invoice with the same number and date (sdi.md §6). */
    public const int RESEND_DAYS = 5;

    public function __construct(
        private ConnectionInterface $db,
        private DocumentContext $context,
        private NumberSequence $sequence,
        private FatturaPaWriter $writer,
        private FatturaPaValidator $validator,
        private CourtesyPdfRenderer $pdf,
        private StoreFile $files,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    /**
     * @throws ValidationException when the profile, the customer or the lines are not ready
     */
    public function issue(int $invoiceId, ?CarbonImmutable $issuedOn = null): InvoiceData
    {
        $issuedOn = ($issuedOn ?? Date::today())->startOfDay();

        if ($issuedOn->greaterThan(Date::today())) {
            throw ValidationException::withMessages(['issued_on' => 'La data di emissione non può essere nel futuro.']);
        }

        return $this->db->transaction(function () use ($invoiceId, $issuedOn): InvoiceData {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

            if ($invoice->status->isIssued()) {
                throw new LogicException("Invoice {$invoiceId} is already issued.");
            }

            $series = $this->context->series()->get($invoice->series);
            $year = $issuedOn->year;
            $number = $this->sequence->next($series->code, $year, $series->openingNumberFor($year));

            $data = $this->finalise($invoice, $issuedOn, $year, $number, $series->render($number, $year));

            $this->audit->record('invoicing.invoice.issued', $data->reference(), ['number' => $data->numberText, 'type' => $invoice->type->value, 'total' => $data->total->toDecimalString(), 'customer' => $data->customerName]);
            $this->events->dispatch(new InvoiceIssued($invoice->id));

            return $data;
        });
    }

    /**
     * A rejected invoice counts as never issued (sdi.md §6): within five
     * days of the rejection it goes out again with the same number and
     * date, rebuilt from its lines, with a new file name.
     *
     * @throws ValidationException when the window is over, or the content is still not ready
     * @throws LogicException when the invoice is not rejected
     */
    public function reissue(int $invoiceId): InvoiceData
    {
        return $this->db->transaction(function () use ($invoiceId): InvoiceData {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

            if ($invoice->status !== InvoiceStatus::Rejected) {
                throw new LogicException("Invoice {$invoiceId} is {$invoice->status->value}: only a rejected invoice is reissued.");
            }

            $deadline = ($invoice->rejected_at ?? Date::now())->startOfDay()->addDays(self::RESEND_DAYS);
            if (Date::today()->greaterThan($deadline)) {
                throw ValidationException::withMessages(['status' => 'Sono passati più di '.self::RESEND_DAYS.' giorni dallo scarto: annulla la fattura ed emettine una nuova.']);
            }

            $issuedOn = $invoice->issued_on ?? throw new LogicException("Invoice {$invoiceId} has no date.");
            $year = $invoice->year ?? throw new LogicException("Invoice {$invoiceId} has no year.");
            $number = $invoice->number ?? throw new LogicException("Invoice {$invoiceId} has no number.");
            $numberText = $invoice->number_text ?? throw new LogicException("Invoice {$invoiceId} has no number.");

            $data = $this->finalise($invoice, $issuedOn, $year, $number, $numberText);

            $this->audit->record('invoicing.invoice.reissued', $data->reference(), ['number' => $numberText, 'file' => $data->sdiFileName]);
            $this->events->dispatch(new InvoiceIssued($invoice->id));

            return $data;
        });
    }

    /**
     * Freezes the content under the given number: snapshots, totals, XML
     * (validated), PDF, files. Shared by the first issue and a reissue.
     *
     * @throws ValidationException
     */
    private function finalise(Invoice $invoice, CarbonImmutable $issuedOn, int $year, int $number, string $numberText): InvoiceData
    {
        $profile = $this->context->profile();
        $seller = PartySnapshot::ofSeller($profile);

        if ($seller->missingForFatturaPa() !== []) {
            throw ValidationException::withMessages(['business_profile' => 'Completa il profilo aziendale (nome, partita IVA o codice fiscale, indirizzo) prima di emettere.']);
        }

        $contact = $this->context->customer($invoice->contact_id);
        $customer = PartySnapshot::ofCustomer($contact);

        if ($customer->missingForFatturaPa() !== []) {
            throw ValidationException::withMessages(['contact_id' => 'Il contatto non ha un\'identità fiscale completa (nome, partita IVA o codice fiscale, indirizzo con CAP e comune).']);
        }

        $related = null;
        if ($invoice->type === DocumentType::CreditNote) {
            $related = Invoice::query()->find($invoice->related_invoice_id ?? 0);

            if ($related === null || ! $related->status->isIssued()) {
                throw ValidationException::withMessages(['related_invoice_id' => 'La nota di credito storna una fattura emessa.']);
            }
        }

        $computed = $this->context->compute($invoice->lineInputs(), $invoice->currency);
        $defaults = $this->context->defaults();
        $progressivo = self::progressivo($this->sequence->next(self::PROGRESSIVO_SERIES, 0));
        $dueOn = $invoice->type === DocumentType::Invoice ? $issuedOn->addDays($invoice->payment_terms_days) : null;

        $document = new FatturaPaDocument(
            progressivoInvio: $progressivo,
            sdiAddress: $contact->sdiAddress(),
            seller: $seller,
            regime: $profile->regime->value,
            customer: $customer,
            type: $invoice->type,
            currency: $invoice->currency,
            date: $issuedOn,
            number: $numberText,
            lines: $computed['lines'],
            totals: $computed['totals'],
            dueOn: $dueOn,
            relatedNumber: $related?->number_text,
            relatedDate: $related?->issued_on,
            causale: $profile->regime === RegimeFiscale::Forfettario && $defaults->legalWording !== null ? [$defaults->legalWording] : [],
        );

        $xml = $this->writer->write($document);
        $errors = $this->validator->errors($xml);

        if ($errors !== []) {
            throw new LogicException("The XML for invoice {$invoice->id} does not validate: ".implode('; ', $errors));
        }

        $pdf = $this->pdf->render(
            title: $invoice->type->label().' '.$numberText,
            seller: $seller,
            customer: $customer,
            date: $issuedOn,
            dueOn: $dueOn,
            lines: $computed['lines'],
            totals: $computed['totals'],
            legalWording: $profile->regime === RegimeFiscale::Forfettario ? $defaults->legalWording : null,
            notes: $invoice->notes,
            relatedTitle: $related === null ? null : $related->type->label().' '.$related->number_text,
        );

        $reference = $invoice->toData()->reference();
        $xmlFile = $this->files->store($reference, 'xml', $document->fileName(), $xml, 'application/xml');
        $pdfFile = $this->files->store($reference, 'pdf', self::pdfName($invoice->type, $numberText), $pdf, 'application/pdf');

        $invoice->forceFill([
            'status' => InvoiceStatus::Issued,
            'year' => $year,
            'number' => $number,
            'number_text' => $numberText,
            'issued_on' => $issuedOn->toDateString(),
            'due_on' => $dueOn?->toDateString(),
            'customer_name' => $customer->name,
            'lines' => array_map(fn ($line): array => $line->toArray(), $computed['lines']),
            'seller' => $seller->toArray(),
            'customer' => $customer->toArray(),
            'regime' => $profile->regime->value,
            'totals' => $computed['totals']->toArray(),
            'total_cents' => $computed['totals']->total->cents,
            'sdi_file_name' => $document->fileName(),
            'progressivo_invio' => $progressivo,
            'xml_file_id' => $xmlFile->id,
            'pdf_file_id' => $pdfFile->id,
            'issued_at' => Date::now(),
            'sent_at' => null,
            'sent_channel' => null,
            'sdi_identifier' => null,
            'sdi_outcome_at' => null,
            'sdi_errors' => null,
            'rejected_at' => null,
        ])->save();

        return $invoice->toData();
    }

    /**
     * The 5-character ProgressivoInvio from the gapless counter, in base 36.
     */
    public static function progressivo(int $counter): string
    {
        return str_pad(strtoupper(base_convert((string) $counter, 10, 36)), 5, '0', STR_PAD_LEFT);
    }

    public static function pdfName(DocumentType $type, string $numberText): string
    {
        return ($type === DocumentType::CreditNote ? 'nota-di-credito-' : 'fattura-').preg_replace('/[^A-Za-z0-9]+/', '-', $numberText).'.pdf';
    }
}
