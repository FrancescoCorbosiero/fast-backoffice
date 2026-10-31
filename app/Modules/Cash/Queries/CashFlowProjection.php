<?php

declare(strict_types=1);

namespace App\Modules\Cash\Queries;

use App\Kernel\Dates\Months;
use App\Kernel\Money\Money;
use App\Modules\Cash\Contracts\CashFlowSchedule;
use App\Modules\Cash\Data\ExpectedFlow;
use App\Modules\Cash\Data\ProjectedMonth;
use App\Modules\Cash\Data\Projection;
use App\Modules\Cash\Enums\Direction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * The cash-flow projection: the sum of every CashFlowSchedule's pending
 * flows, bucketed by calendar month. Items pending from before the window
 * count in its first month and are flagged overdue (design.md §5.4).
 */
final readonly class CashFlowProjection
{
    private string $currency;

    public function __construct(
        private Container $container,
        Repository $config,
    ) {
        $currency = $config->get('backoffice.currency');
        $this->currency = is_string($currency) ? $currency : 'EUR';
    }

    /**
     * $months calendar months, starting with the one containing $today.
     */
    public function project(CarbonImmutable $today, int $months): Projection
    {
        $months = max(1, $months);
        $today = $today->startOfDay();
        $first = $today->startOfMonth();
        $until = Months::add($first, $months - 1)->endOfMonth();

        $flows = [];

        /** @var iterable<CashFlowSchedule> $schedules */
        $schedules = $this->container->tagged(CashFlowSchedule::TAG);

        foreach ($schedules as $schedule) {
            foreach ($schedule->pending($until) as $flow) {
                if ($flow->date->lessThanOrEqualTo($until)) {
                    $flows[] = $flow;
                }
            }
        }

        usort($flows, fn (ExpectedFlow $a, ExpectedFlow $b): int => [$a->date, $a->description] <=> [$b->date, $b->description]);

        /** @var array<string, CarbonImmutable> $starts */
        $starts = [];
        /** @var array<string, int> $ins */
        $ins = [];
        /** @var array<string, int> $outs */
        $outs = [];

        for ($i = 0; $i < $months; $i++) {
            $start = Months::add($first, $i);
            $starts[$start->format('Y-m')] = $start;
            $ins[$start->format('Y-m')] = 0;
            $outs[$start->format('Y-m')] = 0;
        }

        foreach ($flows as $flow) {
            $key = $flow->date->lessThan($first) ? $first->format('Y-m') : $flow->date->format('Y-m');

            if ($flow->direction === Direction::In) {
                $ins[$key] += $flow->amount->cents;
            } else {
                $outs[$key] += $flow->amount->cents;
            }
        }

        $projected = [];
        foreach ($starts as $key => $start) {
            $projected[] = new ProjectedMonth($start, new Money($ins[$key], $this->currency), new Money($outs[$key], $this->currency));
        }

        return new Projection(
            today: $today,
            months: $projected,
            flows: $flows,
            in: new Money(array_sum($ins), $this->currency),
            out: new Money(array_sum($outs), $this->currency),
        );
    }
}
