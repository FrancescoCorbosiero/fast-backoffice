<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Widgets;

use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Filament\Amounts;
use App\Modules\Cash\Queries\Analytics;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class YearToDateWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $today = CarbonImmutable::today();
        $analytics = app(Analytics::class);
        $in = $analytics->yearToDate(Direction::In, $today);
        $out = $analytics->yearToDate(Direction::Out, $today);
        $net = $in->minus($out);

        return [
            Stat::make('Entrate '.$today->year, Amounts::format($in))->color('success'),
            Stat::make('Uscite '.$today->year, Amounts::format($out))->color('danger'),
            Stat::make('Netto '.$today->year, Amounts::format($net))->color($net->isNegative() ? 'danger' : 'success'),
        ];
    }
}
