<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Queries;

use App\Modules\Quotes\Data\QuoteData;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Models\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final readonly class Quotes
{
    /**
     * Unanswered and still valid on the day, soonest to expire first.
     *
     * @return list<QuoteData>
     */
    public function open(CarbonImmutable $today): array
    {
        return $this->map(self::openOn(Quote::query(), $today)->orderBy('valid_until')->orderBy('id')->get()->all());
    }

    /**
     * Unanswered and valid for at most this many more days (today counts as 0).
     *
     * @return list<QuoteData>
     */
    public function expiringWithin(CarbonImmutable $today, int $days): array
    {
        $today = $today->startOfDay();

        return $this->map(self::openOn(Quote::query(), $today)->where('valid_until', '<=', $today->addDays($days)->toDateString())->orderBy('valid_until')->orderBy('id')->get()->all());
    }

    /**
     * Unanswered and past their validity.
     *
     * @return list<QuoteData>
     */
    public function expired(CarbonImmutable $today): array
    {
        return $this->map(Quote::query()->whereIn('status', [QuoteStatus::Draft->value, QuoteStatus::Sent->value])
            ->where('valid_until', '<', $today->startOfDay()->toDateString())->orderBy('valid_until')->orderBy('id')->get()->all());
    }

    /**
     * @return list<QuoteData> newest first
     */
    public function forContact(int $contactId, int $limit = 100): array
    {
        return $this->map(Quote::query()->where('contact_id', $contactId)->orderByDesc('issued_on')->orderByDesc('id')->limit($limit)->get()->all());
    }

    /**
     * @param  Builder<Quote>  $query
     * @return Builder<Quote>
     */
    public static function openOn(Builder $query, CarbonImmutable $today): Builder
    {
        return $query->whereIn('status', [QuoteStatus::Draft->value, QuoteStatus::Sent->value])
            ->where('valid_until', '>=', $today->startOfDay()->toDateString());
    }

    /**
     * @param  array<int, Quote>  $quotes
     * @return list<QuoteData>
     */
    private function map(array $quotes): array
    {
        return array_values(array_map(fn (Quote $quote): QuoteData => $quote->toData(), $quotes));
    }
}
