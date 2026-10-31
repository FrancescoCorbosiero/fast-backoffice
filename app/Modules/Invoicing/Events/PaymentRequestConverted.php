<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Events;

final readonly class PaymentRequestConverted
{
    public function __construct(public int $paymentRequestId, public int $invoiceId) {}
}
