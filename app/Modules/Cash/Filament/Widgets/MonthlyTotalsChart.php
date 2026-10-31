<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Widgets;

use App\Modules\Cash\Data\MonthTotals;
use App\Modules\Cash\Queries\Analytics;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

class MonthlyTotalsChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Ultimi 12 mesi';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $series = app(Analytics::class)->monthly(12, CarbonImmutable::today());

        return [
            'labels' => array_map(fn (MonthTotals $month): string => $month->month->isoFormat('MMM YY'), $series),
            'datasets' => [
                ['label' => 'Entrate', 'data' => array_map(fn (MonthTotals $month): float => $month->in->cents / 100, $series), 'backgroundColor' => '#16a34a'],
                ['label' => 'Uscite', 'data' => array_map(fn (MonthTotals $month): float => $month->out->cents / 100, $series), 'backgroundColor' => '#dc2626'],
            ],
        ];
    }
}
