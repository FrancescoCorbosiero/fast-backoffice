<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Listeners;

use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Quotes\Models\Quote;

/**
 * Quotes follow a merged contact.
 */
final class RepointMergedContact
{
    public function handle(ContactsMerged $event): void
    {
        Quote::query()->where('contact_id', $event->mergedContactId)->update(['contact_id' => $event->contactId]);
    }
}
