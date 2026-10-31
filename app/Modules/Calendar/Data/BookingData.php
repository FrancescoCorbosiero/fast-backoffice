<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Data;

use App\Kernel\Reference;
use App\Modules\Calendar\Enums\BookingStatus;
use Carbon\CarbonImmutable;

final readonly class BookingData
{
    public function __construct(
        public int $id,
        public string $uid,
        public string $title,
        public ?string $eventType,
        public BookingStatus $status,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ?string $timezone,
        public ?string $attendeeName,
        public ?string $attendeeEmail,
        public ?string $location,
        public ?int $contactId,
        public ?string $notes,
        public ?string $cancellationReason,
        public string $source,
        public CarbonImmutable $syncedAt,
    ) {}

    public function reference(): Reference
    {
        return Reference::to('booking', $this->id);
    }

    public function isUpcoming(CarbonImmutable $now): bool
    {
        return $this->status->isActive() && $this->startsAt->greaterThanOrEqualTo($now);
    }
}
