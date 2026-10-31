<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Queries;

use App\Kernel\Reference;
use App\Modules\Cash\Data\MovementData;
use App\Modules\Cash\Queries\AllocatedMovements;
use App\Modules\Invoicing\Data\PaymentData;

/**
 * A document's payments: the cash movements allocated to it.
 */
final readonly class InvoicePayments
{
    public function __construct(private AllocatedMovements $allocated) {}

    /**
     * @return list<PaymentData> chronological
     */
    public function forInvoice(int $invoiceId): array
    {
        return $this->for(Reference::to('invoice', $invoiceId));
    }

    /**
     * @return list<PaymentData>
     */
    public function for(Reference $reference): array
    {
        return array_map(fn (MovementData $movement): PaymentData => new PaymentData(
            movementId: $movement->id,
            amount: $movement->signedAmount(),
            paidOn: $movement->occurredOn,
            accountId: $movement->accountId,
            description: $movement->description,
        ), $this->allocated->for($reference));
    }
}
