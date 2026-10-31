<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Events;

final readonly class BookingRecorded
{
    public function __construct(public int $bookingId, public ?int $contactId) {}
}
