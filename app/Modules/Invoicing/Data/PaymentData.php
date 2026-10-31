<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Money\Money;
use Carbon\CarbonImmutable;

/**
 * A payment as recorded: the cash movement allocated to the document.
 */
final readonly class PaymentData
{
    public function __construct(
        public int $movementId,
        public Money $amount,
        public CarbonImmutable $paidOn,
        public int $accountId,
        public string $description,
    ) {}
}
