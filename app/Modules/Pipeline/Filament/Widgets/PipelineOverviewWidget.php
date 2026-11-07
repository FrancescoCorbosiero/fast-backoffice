<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Widgets;

use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\ChangeRequestResource;
use App\Modules\Pipeline\Queries\ChangeRequests;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What the owner has to look at: previews waiting for a decision, deploys
 * in flight, and runs that failed this month. The owner's notification
 * channel (design.md §4.2, Pipeline): the dashboard.
 */
class PipelineOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $counts = app(ChangeRequests::class)->counts(CarbonImmutable::today()->subDays(30));
        $url = fn (ChangeRequestStatus $status): string => ChangeRequestResource::getUrl('index', ['filters' => ['status' => ['value' => $status->value]]]);

        return [
            Stat::make('Anteprime da approvare', (string) $counts['preview_ready'])
                ->description($counts['preview_ready'] === 0 ? 'nessuna richiesta in attesa' : 'richieste con anteprima pronta')
                ->color($counts['preview_ready'] === 0 ? 'success' : 'warning')
                ->url($url(ChangeRequestStatus::PreviewReady)),
            Stat::make('In pubblicazione', (string) $counts['approved'])
                ->description('approvate, in attesa del worker')
                ->color($counts['approved'] === 0 ? 'gray' : 'info')
                ->url($url(ChangeRequestStatus::Approved)),
            Stat::make('Fallite negli ultimi 30 giorni', (string) $counts['failed'])
                ->description($counts['failed'] === 0 ? 'nessun errore' : 'da controllare')
                ->color($counts['failed'] === 0 ? 'success' : 'danger')
                ->url($url(ChangeRequestStatus::Failed)),
        ];
    }
}
