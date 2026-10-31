<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Cash\Actions\DeleteMovement;
use App\Modules\Cash\Queries\AllocatedMovements;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Events\PaymentRemoved;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Removes a payment: the movement goes, the cached paid amount follows,
 * in one transaction. Nothing is left behind in the ledger.
 */
final readonly class RemovePayment
{
    public function __construct(
        private ConnectionInterface $db,
        private DeleteMovement $movements,
        private AllocatedMovements $allocated,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    public function remove(int $invoiceId, int $movementId): InvoiceData
    {
        return $this->db->transaction(function () use ($invoiceId, $movementId): InvoiceData {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
            $reference = $invoice->toData()->reference();

            $this->movements->delete($movementId, $reference);
            $invoice->forceFill(['paid_cents' => $this->allocated->total($reference)->cents])->save();

            $this->audit->record('invoicing.payment.removed', $reference, ['movement' => $movementId]);
            $this->events->dispatch(new PaymentRemoved($invoice->id, $movementId));

            return $invoice->toData();
        });
    }
}
