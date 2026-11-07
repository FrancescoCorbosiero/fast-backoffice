<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Filament\Widgets;

use App\Kernel\Money\Money;
use App\Modules\Quotes\Data\QuoteData;
use App\Modules\Quotes\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Modules\Quotes\Filament\Resources\Quotes\QuoteResource;
use App\Modules\Quotes\Queries\Quotes;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Quotes waiting for an answer, the ones about to expire, the ones that
 * did: each number opens the list on that tab.
 */
class QuotesOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $today = CarbonImmutable::today();
        $quotes = app(Quotes::class);
        $open = $quotes->open($today);
        $expiring = $quotes->expiringWithin($today, QuoteResource::EXPIRING_DAYS);
        $expired = $quotes->expired($today);

        return [
            Stat::make('Preventivi aperti', (string) count($open))
                ->description($open === [] ? 'nessuno in attesa di risposta' : self::total($open).' in attesa di risposta')
                ->url(QuoteResource::getUrl('index', ['activeTab' => ListQuotes::TAB_OPEN])),
            Stat::make('In scadenza entro '.QuoteResource::EXPIRING_DAYS.' giorni', (string) count($expiring))
                ->description($expiring === [] ? 'nessuno' : self::total($expiring).' · il primo il '.$expiring[0]->validUntil->isoFormat('D MMM'))
                ->color($expiring === [] ? 'success' : 'warning')
                ->url(QuoteResource::getUrl('index', ['activeTab' => ListQuotes::TAB_EXPIRING])),
            Stat::make('Scaduti senza risposta', (string) count($expired))
                ->description($expired === [] ? 'nessuno' : self::total($expired).' da rifiutare o duplicare')
                ->color($expired === [] ? 'success' : 'danger')
                ->url(QuoteResource::getUrl('index', ['activeTab' => ListQuotes::TAB_EXPIRED])),
        ];
    }

    /**
     * @param  list<QuoteData>  $quotes
     */
    private static function total(array $quotes): string
    {
        return Money::sum(array_map(fn (QuoteData $quote): Money => $quote->total, $quotes))->format();
    }
}
