<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\SendChannel;
use App\Modules\Invoicing\Events\InvoiceSent;
use App\Modules\Invoicing\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * The XML was uploaded through the Agenzia's portal by hand (sdi.md §3).
 */
final readonly class MarkSentManually
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    public function mark(int $invoiceId, ?CarbonImmutable $sentOn = null): InvoiceData
    {
        return $this->db->transaction(function () use ($invoiceId, $sentOn): InvoiceData {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

            if (! $invoice->status->canBeSent()) {
                throw new LogicException("Invoice {$invoiceId} is {$invoice->status->value}: only an issued, unsent invoice can be marked as sent.");
            }

            $invoice->forceFill([
                'status' => InvoiceStatus::SentManually,
                'sent_at' => $sentOn ?? Date::now(),
                'sent_channel' => SendChannel::Manual,
            ])->save();

            $data = $invoice->toData();
            $this->audit->record('invoicing.invoice.sent', $data->reference(), ['channel' => SendChannel::Manual->value, 'number' => $invoice->number_text]);
            $this->events->dispatch(new InvoiceSent($invoice->id, SendChannel::Manual));

            return $data;
        });
    }
}
