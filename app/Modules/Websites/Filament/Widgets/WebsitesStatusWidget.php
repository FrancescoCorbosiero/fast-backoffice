<?php

declare(strict_types=1);

namespace App\Modules\Websites\Filament\Widgets;

use App\Modules\Websites\Data\WebsiteData;
use App\Modules\Websites\Filament\Resources\Websites\WebsiteResource;
use App\Modules\Websites\Queries\Websites;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Sites down right now, and hosting renewals overdue or due within a
 * month. Each stat leads to the sites it counts: the one site that is
 * down, or the list filtered the same way.
 */
class WebsitesStatusWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $today = CarbonImmutable::today();
        $websites = app(Websites::class);
        $down = $websites->down();
        $active = $websites->active();
        $renewals = array_values(array_filter($active, fn (WebsiteData $w): bool => $w->hasHostingRenewal() && $w->hostingRenewsOn !== null && $w->hostingRenewsOn->lessThanOrEqualTo($today->addDays(30))));
        $overdue = array_values(array_filter($renewals, fn (WebsiteData $w): bool => $w->hostingRenewalOverdue($today)));

        return [
            Stat::make('Siti giù', (string) count($down))
                ->description($down === [] ? 'tutti i siti controllati rispondono' : implode(', ', array_map(fn (WebsiteData $w): string => $w->name, $down)))
                ->color($down === [] ? 'success' : 'danger')
                ->url(count($down) === 1 ? WebsiteResource::getUrl('view', ['record' => $down[0]->id]) : WebsiteResource::getUrl('index', ['filters' => ['down' => ['isActive' => true]]])),
            Stat::make('Rinnovi hosting entro 30 giorni', (string) count($renewals))
                ->description(count($overdue) > 0 ? count($overdue).' già scaduti' : 'nessuno scaduto')
                ->color($overdue !== [] ? 'danger' : ($renewals === [] ? 'success' : 'warning'))
                ->url(WebsiteResource::getUrl('index', ['filters' => ['hosting_due' => ['isActive' => true]], 'sort' => 'hosting_renews_on:asc'])),
        ];
    }
}
