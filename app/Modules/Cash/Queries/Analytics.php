<?php

declare(strict_types=1);

namespace App\Modules\Cash\Queries;

use App\Kernel\Dates\Months;
use App\Kernel\Money\Money;
use App\Modules\Cash\Data\CategoryTotal;
use App\Modules\Cash\Data\MonthTotals;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Models\Movement;
use App\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Query\Builder;

/**
 * The figures on the dashboard, as queries over movements (design.md
 * §5.4): there is no ledger table to keep in sync. Each method is one
 * query.
 */
final readonly class Analytics
{
    private string $currency;

    public function __construct(Repository $config)
    {
        $currency = $config->get('backoffice.currency');
        $this->currency = is_string($currency) ? $currency : 'EUR';
    }

    /**
     * In and out per calendar month, the last $months months ending with
     * the month of $endingAt, months without movements included.
     *
     * @return list<MonthTotals>
     */
    public function monthly(int $months, CarbonImmutable $endingAt, bool $taxableOnly = true): array
    {
        $months = max(1, $months);
        $last = $endingAt->startOfMonth();
        $first = Months::subtract($last, $months - 1);

        $rows = Movement::query()->toBase()
            ->selectRaw("to_char(occurred_on, 'YYYY-MM') as month, direction, sum(amount_cents) as total")
            ->whereBetween('occurred_on', [$first->toDateString(), $last->endOfMonth()->toDateString()])
            ->when($taxableOnly, fn (Builder $query) => $query->where('is_taxable', true))
            ->groupBy('month', 'direction')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[Row::string($row, 'month')][Row::string($row, 'direction')] = Row::int($row, 'total');
        }

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $month = Months::add($first, $i);
            $key = $month->format('Y-m');
            $series[] = new MonthTotals(
                month: $month,
                in: new Money($totals[$key]['in'] ?? 0, $this->currency),
                out: new Money($totals[$key]['out'] ?? 0, $this->currency),
            );
        }

        return $series;
    }

    /**
     * The total in one direction from the 1st of January to $asOf.
     */
    public function yearToDate(Direction $direction, CarbonImmutable $asOf, bool $taxableOnly = true): Money
    {
        return $this->total($direction, $asOf->startOfYear(), $asOf, $taxableOnly);
    }

    public function total(Direction $direction, CarbonImmutable $from, CarbonImmutable $to, bool $taxableOnly = true): Money
    {
        $sum = Movement::query()->toBase()
            ->where('direction', $direction->value)
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->when($taxableOnly, fn (Builder $query) => $query->where('is_taxable', true))
            ->sum('amount_cents');

        return new Money((int) $sum, $this->currency);
    }

    /**
     * Totals per category in a period, largest first; movements without a
     * category are grouped as "(senza categoria)".
     *
     * @return list<CategoryTotal>
     */
    public function byCategory(Direction $direction, CarbonImmutable $from, CarbonImmutable $to, bool $taxableOnly = true): array
    {
        $rows = Movement::query()->toBase()
            ->leftJoin('cash_categories', 'cash_categories.id', '=', 'cash_movements.category_id')
            ->selectRaw('cash_movements.category_id, cash_categories.name, sum(cash_movements.amount_cents) as total, count(*) as movements')
            ->where('cash_movements.direction', $direction->value)
            ->whereBetween('cash_movements.occurred_on', [$from->toDateString(), $to->toDateString()])
            ->when($taxableOnly, fn (Builder $query) => $query->where('cash_movements.is_taxable', true))
            ->groupBy('cash_movements.category_id', 'cash_categories.name')
            ->orderByDesc('total')
            ->get();

        return array_values($rows->map(fn (object $row): CategoryTotal => new CategoryTotal(
            categoryId: Row::nullableInt($row, 'category_id'),
            name: Row::nullableInt($row, 'category_id') === null ? '(senza categoria)' : Row::string($row, 'name'),
            total: new Money(Row::int($row, 'total'), $this->currency),
            movements: Row::int($row, 'movements'),
        ))->all());
    }
}
