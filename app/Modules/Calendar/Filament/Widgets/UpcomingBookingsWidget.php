<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Filament\Widgets;

use App\Modules\Calendar\Queries\Bookings;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The next appointments, from Cal.com.
 */
class UpcomingBookingsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    protected function getStats(): array
    {
        $now = CarbonImmutable::now();
        $upcoming = app(Bookings::class)->upcoming($now, 50);
        $thisWeek = array_values(array_filter($upcoming, fn ($b): bool => $b->startsAt->lessThan($now->addDays(7))));
        $next = $upcoming[0] ?? null;

        return [
            Stat::make('Appuntamenti nei prossimi 7 giorni', (string) count($thisWeek))
                ->description($next === null ? 'nessuno in agenda' : 'prossimo: '.$next->startsAt->isoFormat('ddd D MMM HH:mm').' · '.($next->attendeeName ?? $next->title)),
        ];
    }
}
