<?php

declare(strict_types=1);

namespace App\Modules\Leads\Filament\Widgets;

use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Queries\Leads;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The pipeline at a glance: open leads by stage, and who to call back.
 */
class LeadsPipelineWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected function getStats(): array
    {
        $leads = app(Leads::class);
        $pipeline = $leads->pipeline();
        $due = $leads->followUpsDue(CarbonImmutable::today());
        $open = $pipeline[LeadStatus::New->value] + $pipeline[LeadStatus::Contacted->value] + $pipeline[LeadStatus::Qualified->value];

        return [
            Stat::make('Lead aperti', (string) $open)
                ->description(sprintf('%d nuovi · %d contattati · %d qualificati', $pipeline[LeadStatus::New->value], $pipeline[LeadStatus::Contacted->value], $pipeline[LeadStatus::Qualified->value])),
            Stat::make('Da ricontattare', (string) count($due))
                ->description($due === [] ? 'nessuno in ritardo' : implode(', ', array_slice(array_map(fn ($l): string => $l->name, $due), 0, 3)))
                ->color($due === [] ? 'success' : 'warning'),
            Stat::make('Vinti / persi', $pipeline[LeadStatus::Won->value].' / '.$pipeline[LeadStatus::Lost->value]),
        ];
    }
}
