<?php

declare(strict_types=1);

namespace App\Modules\Cash\Data;

use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Cash\Enums\Direction;
use Carbon\CarbonImmutable;

/**
 * One expected movement of money, contributed to the projection by a
 * CashFlowSchedule: a receivable, a recurring invoice, a renewal…
 */
final readonly class ExpectedFlow
{
    public CarbonImmutable $date;

    public function __construct(
        public Direction $direction,
        CarbonImmutable $date,
        public Money $amount,
        public string $description,
        public string $source,
        public ?Reference $reference = null,
    ) {
        $this->date = $date->startOfDay();
    }

    public static function in(CarbonImmutable $date, Money $amount, string $description, string $source, ?Reference $reference = null): self
    {
        return new self(Direction::In, $date, $amount, $description, $source, $reference);
    }

    public static function out(CarbonImmutable $date, Money $amount, string $description, string $source, ?Reference $reference = null): self
    {
        return new self(Direction::Out, $date, $amount, $description, $source, $reference);
    }
}
