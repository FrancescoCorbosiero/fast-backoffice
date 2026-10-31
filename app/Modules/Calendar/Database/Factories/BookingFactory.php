<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Database\Factories;

use App\Modules\Calendar\Enums\BookingStatus;
use App\Modules\Calendar\Models\Booking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_uid' => 'uid-'.fake()->unique()->numberBetween(1, 999_999),
            'title' => 'Call conoscitiva',
            'event_type' => 'call-30',
            'status' => BookingStatus::Accepted,
            'starts_at' => '2026-03-20 10:00:00',
            'ends_at' => '2026-03-20 10:30:00',
            'timezone' => 'Europe/Rome',
            'attendee_name' => 'Giulia Verdi',
            'attendee_email' => 'giulia@verdi.example',
            'source' => 'sync',
            'synced_at' => '2026-03-15 10:00:00',
        ];
    }
}
