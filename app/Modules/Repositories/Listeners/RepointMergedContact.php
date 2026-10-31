<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Listeners;

use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Repositories\Models\Repository;

final class RepointMergedContact
{
    public function handle(ContactsMerged $event): void
    {
        Repository::query()->where('contact_id', $event->mergedContactId)->update(['contact_id' => $event->contactId]);
    }
}
