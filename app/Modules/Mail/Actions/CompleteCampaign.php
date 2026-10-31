<?php

declare(strict_types=1);

namespace App\Modules\Mail\Actions;

use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Events\CampaignCompleted;
use App\Modules\Mail\Models\Campaign;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;

/**
 * A campaign is sent when no recipient is queued any more — not when the
 * jobs were pushed (hive-crm marked campaigns sent while merely queued).
 */
final readonly class CompleteCampaign
{
    public function __construct(
        private ConnectionInterface $db,
        private Dispatcher $events,
    ) {}

    public function completeIfDone(int $campaignId): bool
    {
        return $this->db->transaction(function () use ($campaignId): bool {
            $campaign = Campaign::query()->lockForUpdate()->find($campaignId);
            if ($campaign === null || $campaign->status !== CampaignStatus::Sending) {
                return false;
            }

            if ($campaign->recipients()->where('status', RecipientStatus::Queued->value)->exists()) {
                return false;
            }

            $campaign->forceFill(['status' => CampaignStatus::Sent, 'completed_at' => Date::now()])->save();
            $this->events->dispatch(new CampaignCompleted($campaign->id));

            return true;
        });
    }
}
