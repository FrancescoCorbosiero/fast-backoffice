<?php

declare(strict_types=1);

namespace App\Modules\Domains\Listeners;

use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Domains\Models\Domain;

final class RepointMergedContact
{
    public function handle(ContactsMerged $event): void
    {
        Domain::query()->where('contact_id', $event->mergedContactId)->update(['contact_id' => $event->contactId]);
    }
}
