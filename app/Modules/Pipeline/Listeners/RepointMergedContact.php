<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Listeners;

use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Models\PortalSignIn;

/**
 * Requests and sign-ins of a merged duplicate follow the contact kept.
 */
final class RepointMergedContact
{
    public function handle(ContactsMerged $event): void
    {
        ChangeRequest::query()->where('contact_id', $event->mergedContactId)->update(['contact_id' => $event->contactId]);
        PortalSignIn::query()->where('contact_id', $event->mergedContactId)->update(['contact_id' => $event->contactId]);
    }
}
