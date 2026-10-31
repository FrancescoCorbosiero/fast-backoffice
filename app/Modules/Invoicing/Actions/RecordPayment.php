<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Cash\Actions\RecordMovement;
use App\Modules\Cash\Data\MovementInput;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Queries\AllocatedMovements;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Data\PaymentInput;
use App\Modules\Invoicing\Enums\DocumentType;
use App\Modules\Invoicing\Events\InvoicePaid;
use App\Modules\Invoicing\Events\PaymentRecorded;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

/**
 * A payment is one cash movement allocated to the invoice (design.md
 * §5.4): this records it and the invoice's cached paid amount in one
 * transaction. There is no payment table to drift.
 */
final readonly class RecordPayment
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordMovement $movements,
        private AllocatedMovements $allocated,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    /**
     * @throws ValidationException
     */
    public function record(int $invoiceId, PaymentInput $payment): InvoiceData
    {
        return $this->db->transaction(function () use ($invoiceId, $payment): InvoiceData {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
            $data = $invoice->toData();

            if (! $invoice->status->isOutstanding() || $invoice->type !== DocumentType::Invoice) {
                throw ValidationException::withMessages(['invoice' => 'Si incassano solo fatture emesse.']);
            }

            if (! $payment->amount->isPositive() || $payment->amount->greaterThan($data->outstanding())) {
                throw ValidationException::withMessages(['amount' => 'L\'incasso è tra zero e il residuo di '.$data->outstanding()->format().'.']);
            }

            $wasPaid = $data->paymentState(Date::today())->value === 'paid';

            $movement = $this->movements->record(new MovementInput(
                accountId: $payment->accountId,
                direction: Direction::In,
                amount: $payment->amount,
                occurredOn: $payment->paidOn,
                description: $payment->description ?? 'Incasso '.$data->title(),
                contactId: $invoice->contact_id,
                allocation: $data->reference(),
                notes: $payment->notes,
            ));

            $invoice->forceFill(['paid_cents' => $this->allocated->total($data->reference())->cents])->save();
            $data = $invoice->toData();

            $this->audit->record('invoicing.payment.recorded', $data->reference(), ['movement' => $movement->id, 'amount' => $payment->amount->toDecimalString(), 'paid_on' => $payment->paidOn->toDateString()]);
            $this->events->dispatch(new PaymentRecorded($invoice->id, $movement->id));

            if (! $wasPaid && $data->outstanding()->isZero()) {
                $this->events->dispatch(new InvoicePaid($invoice->id));
            }

            return $data;
        });
    }
}
