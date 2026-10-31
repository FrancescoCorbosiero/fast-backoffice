<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Listeners;

use App\Modules\Calendar\Models\Booking;
use App\Modules\Contacts\Events\ContactsMerged;

final class RepointMergedContact
{
    public function handle(ContactsMerged $event): void
    {
        Booking::query()->where('contact_id', $event->mergedContactId)->update(['contact_id' => $event->contactId]);
    }
}
