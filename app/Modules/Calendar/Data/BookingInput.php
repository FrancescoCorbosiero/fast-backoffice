<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Data;

use App\Modules\Calendar\Enums\BookingStatus;
use Carbon\CarbonImmutable;

/**
 * A booking as Cal.com describes it, normalised: the same shape whether it
 * came from a webhook or from the API.
 */
final readonly class BookingInput
{
    /**
     * @param  array<string, mixed>  $payload  what Cal.com sent, kept for reference
     */
    public function __construct(
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
        public ?string $notes,
        public ?string $cancellationReason,
        public array $payload = [],
    ) {}
}
