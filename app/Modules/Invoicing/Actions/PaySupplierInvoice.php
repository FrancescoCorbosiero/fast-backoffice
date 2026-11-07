<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Cash\Actions\RecordMovement;
use App\Modules\Cash\Data\MovementInput;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Queries\AllocatedMovements;
use App\Modules\Invoicing\Data\PaymentInput;
use App\Modules\Invoicing\Data\SupplierInvoiceData;
use App\Modules\Invoicing\Models\SupplierInvoice;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * Paying a supplier invoice is one outgoing cash movement allocated to
 * it, like an invoice's payment in reverse (design.md §5.4): the movement
 * and the cached paid amount move in one transaction.
 */
final readonly class PaySupplierInvoice
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordMovement $movements,
        private AllocatedMovements $allocated,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function pay(int $supplierInvoiceId, PaymentInput $payment): SupplierInvoiceData
    {
        return $this->db->transaction(function () use ($supplierInvoiceId, $payment): SupplierInvoiceData {
            $invoice = SupplierInvoice::query()->lockForUpdate()->findOrFail($supplierInvoiceId);
            $data = $invoice->toData();

            if (! $payment->amount->isPositive() || $payment->amount->greaterThan($data->outstanding())) {
                throw ValidationException::withMessages(['amount' => 'Il pagamento è tra zero e il residuo di '.$data->outstanding()->format().'.']);
            }

            $movement = $this->movements->record(new MovementInput(
                accountId: $payment->accountId,
                direction: Direction::Out,
                amount: $payment->amount,
                occurredOn: $payment->paidOn,
                description: $payment->description ?? 'Pagamento '.$data->title(),
                contactId: $invoice->contact_id,
                allocation: $data->reference(),
                notes: $payment->notes,
            ));

            // Outgoing movements count negative in the allocated total: what was paid is its opposite.
            $invoice->forceFill(['paid_cents' => -$this->allocated->total($data->reference())->cents])->save();

            $this->audit->record('invoicing.supplier_invoice.paid', $data->reference(), ['movement' => $movement->id, 'amount' => $payment->amount->toDecimalString(), 'paid_on' => $payment->paidOn->toDateString()]);

            return $invoice->toData();
        });
    }
}
