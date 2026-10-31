<?php

declare(strict_types=1);

namespace App\Modules\Mail\Listeners;

use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Mail\Models\CampaignRecipient;

/**
 * Recipients follow a merged contact, except where the kept contact was
 * already in that campaign: the duplicate row stays on record as it was.
 */
final class RepointMergedContact
{
    public function handle(ContactsMerged $event): void
    {
        $alreadyIn = CampaignRecipient::query()->where('contact_id', $event->contactId)->pluck('campaign_id')->all();

        CampaignRecipient::query()->where('contact_id', $event->mergedContactId)->whereNotIn('campaign_id', $alreadyIn)->update(['contact_id' => $event->contactId]);
    }
}
