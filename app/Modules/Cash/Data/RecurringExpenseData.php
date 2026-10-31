<?php

declare(strict_types=1);

namespace App\Modules\Cash\Data;

use App\Kernel\Dates\Frequency;
use App\Kernel\Money\Money;
use Carbon\CarbonImmutable;

final readonly class RecurringExpenseData
{
    public function __construct(
        public int $id,
        public string $name,
        public Money $amount,
        public Frequency $frequency,
        public CarbonImmutable $startedOn,
        public CarbonImmutable $nextDueOn,
        public ?int $accountId,
        public ?int $categoryId,
        public ?int $contactId,
        public bool $isActive,
    ) {}

    public function isOverdue(CarbonImmutable $today): bool
    {
        return $this->isActive && $this->nextDueOn->lessThan($today->startOfDay());
    }
}
