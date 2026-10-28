<?php

declare(strict_types=1);

namespace App\Modules\Cash\Data;

use App\Kernel\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Expected money in and out, month by month, from every CashFlowSchedule.
 * Derived on every call, never stored. Flows still pending from before
 * the window (overdue) count in its first month.
 */
final readonly class Projection
{
    /**
     * @param  list<ProjectedMonth>  $months  one per calendar month of the window
     * @param  list<ExpectedFlow>  $flows  chronological
     */
    public function __construct(
        public CarbonImmutable $today,
        public array $months,
        public array $flows,
        public Money $in,
        public Money $out,
    ) {}

    public function net(): Money
    {
        return $this->in->minus($this->out);
    }

    public function isEmpty(): bool
    {
        return $this->flows === [];
    }

    public function isOverdue(ExpectedFlow $flow): bool
    {
        return $flow->date->lessThan($this->today);
    }

    public function month(string $key): ?ProjectedMonth
    {
        foreach ($this->months as $month) {
            if ($month->key() === $key) {
                return $month;
            }
        }

        return null;
    }
}
