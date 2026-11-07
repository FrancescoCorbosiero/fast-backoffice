<?php

declare(strict_types=1);

namespace App\Modules\Mail\Filament\Widgets;

use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Filament\Resources\Campaigns\CampaignResource;
use App\Modules\Mail\Models\Campaign;
use App\Modules\Mail\Models\CampaignRecipient;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Campaigns in flight (sending or paused), the next scheduled one, and
 * the bounces and complaints of the last week: the signal the sender's
 * reputation depends on. Shown only when there is something to say.
 */
class CampaignsOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 6;

    public static function canView(): bool
    {
        return self::inFlight()->exists() || self::nextScheduled() !== null || self::recentBounces()->exists();
    }

    protected function getStats(): array
    {
        $stats = [];

        $inFlight = self::inFlight()->orderBy('id')->get();
        if ($inFlight->isNotEmpty()) {
            $queued = CampaignRecipient::query()->whereIn('campaign_id', $inFlight->modelKeys())->where('status', RecipientStatus::Queued->value)->count();
            $paused = $inFlight->where('status', CampaignStatus::Paused)->count();
            $stats[] = Stat::make('Campagne in invio', (string) $inFlight->count())
                ->description($queued.' email in coda'.($paused > 0 ? ' · '.$paused.' in pausa' : ''))
                ->color($paused > 0 ? 'warning' : 'info')
                ->url($inFlight->count() === 1 ? CampaignResource::getUrl('view', ['record' => $inFlight->first()->id]) : CampaignResource::getUrl('index', ['filters' => ['status' => ['value' => CampaignStatus::Sending->value]]]));
        }

        $next = self::nextScheduled();
        if ($next !== null && $next->scheduled_at !== null) {
            $stats[] = Stat::make('Prossima campagna programmata', $next->scheduled_at->isoFormat('D MMM HH:mm'))
                ->description($next->name)
                ->color('info')
                ->url(CampaignResource::getUrl('view', ['record' => $next->id]));
        }

        $bounces = self::recentBounces()->get();
        if ($bounces->isNotEmpty()) {
            $complaints = $bounces->where('status', RecipientStatus::Complained)->count();
            $campaignIds = $bounces->pluck('campaign_id')->unique();
            $stats[] = Stat::make('Bounce e spam, ultimi 7 giorni', (string) $bounces->count())
                ->description($complaints > 0 ? $complaints.' segnalate come spam: la reputazione del mittente ne risente' : 'nessuna segnalazione di spam')
                ->color($complaints > 0 ? 'danger' : 'warning')
                ->url($campaignIds->count() === 1 ? CampaignResource::getUrl('view', ['record' => $campaignIds->first(), 'status' => $complaints > 0 ? RecipientStatus::Complained->value : RecipientStatus::Bounced->value]) : CampaignResource::getUrl('index'));
        }

        return $stats;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Campaign>
     */
    private static function inFlight(): \Illuminate\Database\Eloquent\Builder
    {
        return Campaign::query()->whereIn('status', [CampaignStatus::Sending->value, CampaignStatus::Paused->value]);
    }

    private static function nextScheduled(): ?Campaign
    {
        return Campaign::query()->where('status', CampaignStatus::Scheduled->value)->orderBy('scheduled_at')->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<CampaignRecipient>
     */
    private static function recentBounces(): \Illuminate\Database\Eloquent\Builder
    {
        return CampaignRecipient::query()
            ->whereIn('status', [RecipientStatus::Bounced->value, RecipientStatus::Complained->value])
            ->where('updated_at', '>=', CarbonImmutable::now()->subDays(7));
    }
}
