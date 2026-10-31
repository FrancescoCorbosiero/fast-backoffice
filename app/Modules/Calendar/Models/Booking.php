<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Models;

use App\Modules\Calendar\Data\BookingData;
use App\Modules\Calendar\Database\Factories\BookingFactory;
use App\Modules\Calendar\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $external_uid
 * @property string $title
 * @property ?string $event_type
 * @property BookingStatus $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property ?string $timezone
 * @property ?string $attendee_name
 * @property ?string $attendee_email
 * @property ?string $location
 * @property ?int $contact_id
 * @property ?string $notes
 * @property ?string $cancellation_reason
 * @property string $source
 * @property ?array<string, mixed> $payload
 * @property CarbonImmutable $synced_at
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'payload' => 'array',
            'synced_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): BookingFactory
    {
        return BookingFactory::new();
    }

    public function toData(): BookingData
    {
        return new BookingData(
            id: $this->id,
            uid: $this->external_uid,
            title: $this->title,
            eventType: $this->event_type,
            status: $this->status,
            startsAt: $this->starts_at,
            endsAt: $this->ends_at,
            timezone: $this->timezone,
            attendeeName: $this->attendee_name,
            attendeeEmail: $this->attendee_email,
            location: $this->location,
            contactId: $this->contact_id,
            notes: $this->notes,
            cancellationReason: $this->cancellation_reason,
            source: $this->source,
            syncedAt: $this->synced_at,
        );
    }
}
