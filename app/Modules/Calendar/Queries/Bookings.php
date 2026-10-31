<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Queries;

use App\Modules\Calendar\Data\BookingData;
use App\Modules\Calendar\Enums\BookingStatus;
use App\Modules\Calendar\Models\Booking;
use Carbon\CarbonImmutable;

final readonly class Bookings
{
    public function find(int $id): ?BookingData
    {
        return Booking::query()->find($id)?->toData();
    }

    public function byUid(string $uid): ?BookingData
    {
        return Booking::query()->where('external_uid', $uid)->first()?->toData();
    }

    /**
     * Confirmed or pending bookings from now on, soonest first.
     *
     * @return list<BookingData>
     */
    public function upcoming(CarbonImmutable $now, int $limit = 20): array
    {
        return $this->map(Booking::query()->whereIn('status', [BookingStatus::Accepted->value, BookingStatus::Pending->value])
            ->where('starts_at', '>=', $now)->orderBy('starts_at')->limit($limit)->get()->all());
    }

    /**
     * @return list<BookingData> newest first
     */
    public function forContact(int $contactId): array
    {
        return $this->map(Booking::query()->where('contact_id', $contactId)->orderByDesc('starts_at')->get()->all());
    }

    /**
     * @param  array<int, Booking>  $bookings
     * @return list<BookingData>
     */
    private function map(array $bookings): array
    {
        return array_values(array_map(fn (Booking $b): BookingData => $b->toData(), $bookings));
    }
}
