<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Enums\DocumentType;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Validation\ValidationException;

/**
 * A credit note draft stornoing an issued invoice: by default the whole
 * invoice, line by line. Cancelling is not a state: it is a credit note
 * for the full amount (design.md §5.3).
 */
final readonly class CreateCreditNote
{
    public function __construct(private CreateDraft $createDraft) {}

    /**
     * @throws ValidationException
     */
    public function create(int $invoiceId, ?DraftInput $lines = null): InvoiceData
    {
        $invoice = Invoice::query()->findOrFail($invoiceId);

        if (! $invoice->status->isIssued() || $invoice->type !== DocumentType::Invoice) {
            throw ValidationException::withMessages(['related_invoice_id' => 'Una nota di credito storna una fattura emessa.']);
        }

        $input = $lines ?? new DraftInput($invoice->contact_id, $invoice->lineInputs(), 0, 'Storno '.$invoice->type->label().' '.$invoice->number_text);

        return $this->createDraft->create($input, DocumentType::CreditNote, $invoice->id);
    }
}
