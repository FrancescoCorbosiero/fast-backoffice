<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Filament\Resources\Quotes\Pages;

use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Filament\Resources\Quotes\QuoteResource;
use App\Modules\Quotes\Models\Quote;
use App\Modules\Quotes\Queries\Quotes;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * The list opens on the quotes waiting for an answer; the other tabs
 * answer «quali scadono?» and «quali sono scaduti?» with a count before
 * a click. The same keys are the dashboard widget's links.
 */
class ListQuotes extends ListRecords
{
    protected static string $resource = QuoteResource::class;

    public const string TAB_OPEN = 'aperti';

    public const string TAB_EXPIRING = 'in-scadenza';

    public const string TAB_EXPIRED = 'scaduti';

    public const string TAB_ALL = 'tutti';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function getTabs(): array
    {
        $today = Date::today();

        return [
            self::TAB_OPEN => Tab::make('Aperti')
                ->modifyQueryUsing(fn (Builder $query): Builder => Quotes::openOn($query, $today))
                ->badge(fn (): ?int => self::countOrNull(Quotes::openOn(Quote::query(), $today))),
            self::TAB_EXPIRING => Tab::make('In scadenza')
                ->modifyQueryUsing(fn (Builder $query): Builder => QuoteResource::expiring($query, $today))
                ->badge(fn (): ?int => self::countOrNull(QuoteResource::expiring(Quote::query(), $today)))->badgeColor('warning'),
            self::TAB_EXPIRED => Tab::make('Scaduti')
                ->modifyQueryUsing(fn (Builder $query): Builder => QuoteResource::withStatus($query, QuoteStatus::Expired, $today))
                ->badge(fn (): ?int => self::countOrNull(QuoteResource::withStatus(Quote::query(), QuoteStatus::Expired, $today)))->badgeColor('danger'),
            'accettati' => Tab::make('Accettati')
                ->modifyQueryUsing(fn (Builder $query): Builder => QuoteResource::withStatus($query, QuoteStatus::Accepted, $today)),
            'rifiutati' => Tab::make('Rifiutati')
                ->modifyQueryUsing(fn (Builder $query): Builder => QuoteResource::withStatus($query, QuoteStatus::Declined, $today)),
            self::TAB_ALL => Tab::make('Tutti'),
        ];
    }

    /**
     * @param  Builder<Quote>  $query
     */
    private static function countOrNull(Builder $query): ?int
    {
        $count = $query->count();

        return $count > 0 ? $count : null;
    }
}
