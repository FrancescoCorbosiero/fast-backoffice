<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Cash\Actions\ReallocateMovement;
use App\Modules\Cash\Queries\AllocatedMovements;
use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Enums\PaymentRequestStatus;
use App\Modules\Invoicing\Events\PaymentRequestConverted;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\PaymentRequest;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * Services are deemed performed when paid (art. 6 DPR 633/72): a paid
 * request becomes an invoice dated on the payment, and the payment's
 * movement moves over to the invoice, which is therefore paid.
 */
final readonly class ConvertPaymentRequest
{
    public function __construct(
        private ConnectionInterface $db,
        private CreateDraft $drafts,
        private IssueInvoice $issue,
        private ReallocateMovement $reallocate,
        private AllocatedMovements $allocated,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    /**
     * @throws ValidationException
     */
    public function convert(int $requestId): InvoiceData
    {
        return $this->db->transaction(function () use ($requestId): InvoiceData {
            $request = PaymentRequest::query()->lockForUpdate()->findOrFail($requestId);

            if ($request->status !== PaymentRequestStatus::Paid || $request->paid_on === null) {
                throw ValidationException::withMessages(['status' => 'Si fattura una richiesta pagata per intero.']);
            }

            $draft = $this->drafts->create(new DraftInput($request->contact_id, $request->lineInputs(), 0, $request->notes));
            $invoice = $this->issue->issue($draft->id, $request->paid_on);

            foreach ($this->allocated->for($request->toData()->reference()) as $movement) {
                $this->reallocate->reallocate($movement->id, $request->toData()->reference(), $invoice->reference());
            }

            $invoiceModel = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $invoiceModel->forceFill(['paid_cents' => $this->allocated->total($invoice->reference())->cents])->save();

            $request->forceFill(['status' => PaymentRequestStatus::Converted, 'invoice_id' => $invoice->id])->save();

            $this->audit->record('invoicing.payment_request.converted', $request->toData()->reference(), ['invoice' => $invoice->id, 'number' => $invoice->numberText]);
            $this->events->dispatch(new PaymentRequestConverted($request->id, $invoice->id));

            return $invoiceModel->toData();
        });
    }
}
