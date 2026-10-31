<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Events;

final readonly class BookingCancelled
{
    public function __construct(public int $bookingId, public ?int $contactId) {}
}
