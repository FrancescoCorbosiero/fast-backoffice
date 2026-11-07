<?php

declare(strict_types=1);

namespace App\Modules\Domains\Filament\Widgets;

use App\Modules\Domains\Data\DomainData;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Filament\Resources\Domains\DomainResource;
use App\Modules\Domains\Queries\Domains;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Domains expiring within a month, the expired ones first. The stat opens
 * the one domain when there is one, the filtered list otherwise.
 */
class DomainsExpiringWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $today = CarbonImmutable::today();
        $expiring = app(Domains::class)->expiringWithin($today, 30);
        $expired = array_values(array_filter($expiring, fn (DomainData $d): bool => $d->isExpired($today)));

        return [
            Stat::make('Domini in scadenza entro 30 giorni', (string) count($expiring))
                ->description($expired !== [] ? count($expired).' già scaduti: '.implode(', ', array_map(fn (DomainData $d): string => $d->name, $expired)) : ($expiring === [] ? 'nessuno' : implode(', ', array_map(fn (DomainData $d): string => $d->name, $expiring))))
                ->color($expired !== [] ? 'danger' : ($expiring === [] ? 'success' : 'warning'))
                ->url(count($expiring) === 1
                    ? DomainResource::getUrl('view', ['record' => $expiring[0]->id])
                    : DomainResource::getUrl('index', ['filters' => ['expiring' => ['isActive' => true], 'status' => ['value' => DomainStatus::Active->value]]])),
        ];
    }
}
