<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Events;

final readonly class PaymentRecorded
{
    public function __construct(public int $invoiceId, public int $movementId) {}
}
