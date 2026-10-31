<?php

declare(strict_types=1);

namespace App\Kernel\Dates;

use Carbon\CarbonImmutable;
use Closure;
use Generator;
use LogicException;

/**
 * Walks the dates a step rule produces, from a first pending date through
 * the end of a window. Shared by the cash-flow projection and every
 * "log occurrence" action, so projected dates are the dates that happen.
 */
final class Occurrences
{
    /**
     * @param  Closure(CarbonImmutable): CarbonImmutable  $step
     * @return Generator<int, CarbonImmutable>
     */
    public static function until(CarbonImmutable $first, Closure $step, CarbonImmutable $end): Generator
    {
        $date = $first;

        while ($date->lessThanOrEqualTo($end)) {
            yield $date;

            $next = $step($date);

            if ($next->lessThanOrEqualTo($date)) {
                throw new LogicException("A schedule step must move forward: {$date->toDateString()} → {$next->toDateString()}.");
            }

            $date = $next;
        }
    }
}
