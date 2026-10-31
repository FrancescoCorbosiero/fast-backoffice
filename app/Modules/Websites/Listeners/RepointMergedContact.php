<?php

declare(strict_types=1);

namespace App\Modules\Websites\Listeners;

use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Websites\Models\Website;

final class RepointMergedContact
{
    public function handle(ContactsMerged $event): void
    {
        Website::query()->where('contact_id', $event->mergedContactId)->update(['contact_id' => $event->contactId]);
    }
}
