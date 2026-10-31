<?php

declare(strict_types=1);

namespace App\Modules\Mail\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Mail\Data\CampaignData;
use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Jobs\SendCampaignEmail;
use App\Modules\Mail\Models\Campaign;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use LogicException;

/**
 * Pause: queued jobs find the campaign paused and do nothing. Resume:
 * every recipient still queued gets a fresh job. Cancel: what is queued
 * is skipped for good.
 */
final readonly class PauseCampaign
{
    public function __construct(
        private Bus $bus,
        private RecordAuditEntry $audit,
        private CompleteCampaign $complete,
    ) {}

    public function pause(int $campaignId): CampaignData
    {
        $campaign = Campaign::query()->findOrFail($campaignId);
        if ($campaign->status !== CampaignStatus::Sending) {
            throw new LogicException("Campaign {$campaignId} is {$campaign->status->value}: only a sending campaign pauses.");
        }
        $campaign->forceFill(['status' => CampaignStatus::Paused])->save();
        $this->audit->record('mail.campaign.paused', $campaign->toData()->reference());

        return $campaign->toData();
    }

    public function resume(int $campaignId): CampaignData
    {
        $campaign = Campaign::query()->findOrFail($campaignId);
        if ($campaign->status !== CampaignStatus::Paused) {
            throw new LogicException("Campaign {$campaignId} is {$campaign->status->value}: only a paused campaign resumes.");
        }
        $campaign->forceFill(['status' => CampaignStatus::Sending])->save();
        $this->audit->record('mail.campaign.resumed', $campaign->toData()->reference());

        foreach ($campaign->recipients()->where('status', RecipientStatus::Queued->value)->orderBy('id')->get() as $recipient) {
            $this->bus->dispatch(new SendCampaignEmail($recipient->id));
        }
        $this->complete->completeIfDone($campaign->id);

        return Campaign::query()->findOrFail($campaignId)->toData();
    }

    public function cancel(int $campaignId): CampaignData
    {
        $campaign = Campaign::query()->findOrFail($campaignId);
        if (! in_array($campaign->status, [CampaignStatus::Scheduled, CampaignStatus::Sending, CampaignStatus::Paused], true)) {
            throw new LogicException("Campaign {$campaignId} is {$campaign->status->value}: nothing to cancel.");
        }
        $campaign->recipients()->where('status', RecipientStatus::Queued->value)->update(['status' => RecipientStatus::Skipped->value, 'last_error' => 'Campagna annullata.']);
        $campaign->forceFill(['status' => CampaignStatus::Cancelled, 'scheduled_at' => null])->save();
        $this->audit->record('mail.campaign.cancelled', $campaign->toData()->reference());

        return $campaign->toData();
    }
}
