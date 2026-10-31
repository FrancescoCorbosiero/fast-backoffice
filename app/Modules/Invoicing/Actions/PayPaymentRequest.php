<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Cash\Actions\RecordMovement;
use App\Modules\Cash\Data\MovementInput;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Queries\AllocatedMovements;
use App\Modules\Invoicing\Data\PaymentInput;
use App\Modules\Invoicing\Data\PaymentRequestData;
use App\Modules\Invoicing\Enums\PaymentRequestStatus;
use App\Modules\Invoicing\Events\PaymentRequestPaid;
use App\Modules\Invoicing\Models\PaymentRequest;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * The customer paid the pro forma: one cash movement allocated to it.
 */
final readonly class PayPaymentRequest
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
    public function pay(int $requestId, PaymentInput $payment): PaymentRequestData
    {
        return $this->db->transaction(function () use ($requestId, $payment): PaymentRequestData {
            $request = PaymentRequest::query()->lockForUpdate()->findOrFail($requestId);
            $data = $request->toData();

            if ($request->status !== PaymentRequestStatus::Open) {
                throw ValidationException::withMessages(['status' => 'La richiesta non è aperta.']);
            }

            $outstanding = $data->total->minus($data->paid);
            if (! $payment->amount->isPositive() || $payment->amount->greaterThan($outstanding)) {
                throw ValidationException::withMessages(['amount' => 'L\'incasso è tra zero e il residuo di '.$outstanding->format().'.']);
            }

            $this->movements->record(new MovementInput(
                accountId: $payment->accountId,
                direction: Direction::In,
                amount: $payment->amount,
                occurredOn: $payment->paidOn,
                description: $payment->description ?? 'Incasso '.$data->title(),
                contactId: $request->contact_id,
                allocation: $data->reference(),
                notes: $payment->notes,
            ));

            $paid = $this->allocated->total($data->reference());
            $request->forceFill([
                'paid_cents' => $paid->cents,
                'status' => $paid->cents >= $request->total_cents ? PaymentRequestStatus::Paid : PaymentRequestStatus::Open,
                'paid_on' => $paid->cents >= $request->total_cents ? $payment->paidOn->toDateString() : null,
            ])->save();

            $this->audit->record('invoicing.payment_request.paid', $data->reference(), ['amount' => $payment->amount->toDecimalString()]);

            if ($request->status === PaymentRequestStatus::Paid) {
                $this->events->dispatch(new PaymentRequestPaid($request->id));
            }

            return $request->toData();
        });
    }
}
