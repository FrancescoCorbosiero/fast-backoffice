<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\DocumentContext;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;
use LogicException;

final readonly class UpdateDraft
{
    public function __construct(
        private ConnectionInterface $db,
        private DocumentContext $context,
    ) {}

    /**
     * @throws ValidationException
     */
    public function update(int $invoiceId, DraftInput $input): InvoiceData
    {
        CreateDraft::validateLines($input->lines);
        $customer = $this->context->customer($input->contactId);

        return $this->db->transaction(function () use ($invoiceId, $input, $customer): InvoiceData {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

            // A rejected invoice counts as never issued (sdi.md §6): it is corrected and reissued.
            if ($invoice->status->isIssued() && $invoice->status !== InvoiceStatus::Rejected) {
                throw new LogicException("Invoice {$invoiceId} is issued: an issued document never changes.");
            }

            $invoice->fill([
                'contact_id' => $customer->id,
                'customer_name' => $customer->displayName,
                'lines' => $input->linesToArray(),
                'payment_terms_days' => $input->paymentTermsDays ?? $invoice->payment_terms_days,
                'notes' => $input->notes,
            ])->save();

            return $invoice->toData();
        });
    }
}
