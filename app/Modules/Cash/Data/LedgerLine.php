<?php

declare(strict_types=1);

namespace App\Modules\Cash\Data;

use App\Kernel\Money\Money;

/**
 * A movement with the account balance after it.
 */
final readonly class LedgerLine
{
    public function __construct(
        public MovementData $movement,
        public Money $balanceAfter,
        public ?string $categoryName,
        public ?string $contactName,
    ) {}
}
