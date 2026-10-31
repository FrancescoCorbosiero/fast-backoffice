<?php

declare(strict_types=1);

namespace App\Kernel\Dates;

use Carbon\CarbonImmutable;
use Generator;
use InvalidArgumentException;

/**
 * A schedule: a frequency and a start date whose day is the anchor day.
 * Each occurrence is the anchor day in its month, clamped to the month's
 * length, so a schedule anchored on the 31st never drifts to the 28th.
 *
 * Occurrence k is anchored(start + k × months), for k ≥ 0.
 */
final readonly class Recurrence
{
    public CarbonImmutable $start;

    public function __construct(
        public Frequency $frequency,
        CarbonImmutable $start,
    ) {
        $this->start = $start->startOfDay();
    }

    public function anchorDay(): int
    {
        return $this->start->day;
    }

    public function occurrence(int $index): CarbonImmutable
    {
        if ($index < 0) {
            throw new InvalidArgumentException('Occurrences are counted from 0.');
        }

        return Months::anchored($this->start->addMonthsNoOverflow($index * $this->frequency->months()), $this->anchorDay());
    }

    /**
     * The first occurrence strictly after $date.
     */
    public function nextAfter(CarbonImmutable $date): CarbonImmutable
    {
        $index = max(0, intdiv(Months::between($this->start, $date), $this->frequency->months()) - 1);

        while (($occurrence = $this->occurrence($index))->lessThanOrEqualTo($date)) {
            $index++;
        }

        return $occurrence;
    }

    /**
     * The first occurrence on or after $date.
     */
    public function nextOnOrAfter(CarbonImmutable $date): CarbonImmutable
    {
        return $this->nextAfter($date->startOfDay()->subDay());
    }

    /**
     * Every occurrence from $from through $to, both inclusive.
     *
     * @return Generator<int, CarbonImmutable>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): Generator
    {
        $occurrence = $this->nextOnOrAfter($from);

        while ($occurrence->lessThanOrEqualTo($to->endOfDay())) {
            yield $occurrence;
            $occurrence = $this->nextAfter($occurrence);
        }
    }
}
