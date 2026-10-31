<?php

declare(strict_types=1);

namespace App\Kernel\Dates;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Month arithmetic that never overflows: Jan 31 + 1 month is Feb 28 (or
 * 29), never Mar 3. Use these instead of Carbon's addMonth(s), which an
 * architecture test forbids.
 */
final class Months
{
    public static function add(CarbonImmutable $date, int $months): CarbonImmutable
    {
        return self::clampDay($date->addMonthsNoOverflow($months), $date->day);
    }

    public static function subtract(CarbonImmutable $date, int $months): CarbonImmutable
    {
        return self::add($date, -$months);
    }

    /**
     * The date in the month of $month whose day is $day, clamped to the
     * month's length: anchor day 31 in April is April 30.
     */
    public static function anchored(CarbonImmutable $month, int $day): CarbonImmutable
    {
        if ($day < 1 || $day > 31) {
            throw new InvalidArgumentException("An anchor day is between 1 and 31, got {$day}.");
        }

        return $month->startOfMonth()->setDay(min($day, $month->daysInMonth))->startOfDay();
    }

    /**
     * Whole months from $from to $to, by calendar month (Jan 31 → Feb 1 is 1).
     */
    public static function between(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return ($to->year - $from->year) * 12 + ($to->month - $from->month);
    }

    private static function clampDay(CarbonImmutable $date, int $day): CarbonImmutable
    {
        return $date->setDay(min($day, $date->daysInMonth));
    }
}
