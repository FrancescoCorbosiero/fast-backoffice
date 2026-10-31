<?php

declare(strict_types=1);

namespace App\Modules\Cash\Data;

use App\Kernel\Money\Money;
use Carbon\CarbonImmutable;

final readonly class ProjectedMonth
{
    public function __construct(
        public CarbonImmutable $start,
        public Money $in,
        public Money $out,
    ) {}

    public function key(): string
    {
        return $this->start->format('Y-m');
    }

    public function net(): Money
    {
        return $this->in->minus($this->out);
    }
}
