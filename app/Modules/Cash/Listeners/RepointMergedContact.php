<?php

declare(strict_types=1);

namespace App\Modules\Cash\Listeners;

use App\Modules\Cash\Models\Movement;
use App\Modules\Cash\Models\RecurringExpense;
use App\Modules\Contacts\Events\ContactsMerged;

/**
 * When two contacts become one, their movements and standing costs follow.
 */
final class RepointMergedContact
{
    public function handle(ContactsMerged $event): void
    {
        Movement::query()->where('contact_id', $event->mergedContactId)->update(['contact_id' => $event->contactId]);
        RecurringExpense::query()->where('contact_id', $event->mergedContactId)->update(['contact_id' => $event->contactId]);
    }
}
