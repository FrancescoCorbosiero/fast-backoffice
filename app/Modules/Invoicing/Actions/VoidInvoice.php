<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Events\InvoiceVoided;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * A rejected invoice whose resend window is over, or that the owner gives
 * up on: it never existed for SdI, its number stays used, a new invoice
 * is issued in its place (sdi.md §6).
 */
final readonly class VoidInvoice
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    /**
     * @throws LogicException unless the invoice is rejected
     */
    public function void(int $invoiceId, ?string $reason = null): InvoiceData
    {
        return $this->db->transaction(function () use ($invoiceId, $reason): InvoiceData {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

            if ($invoice->status !== InvoiceStatus::Rejected) {
                throw new LogicException("Invoice {$invoiceId} is {$invoice->status->value}: only a rejected invoice is voided; anything else is cancelled by a credit note.");
            }

            $invoice->forceFill(['status' => InvoiceStatus::Void, 'voided_at' => Date::now()])->save();

            $data = $invoice->toData();
            $this->audit->record('invoicing.invoice.voided', $data->reference(), ['number' => $invoice->number_text, 'reason' => $reason]);
            $this->events->dispatch(new InvoiceVoided($invoice->id));

            return $data;
        });
    }
}
