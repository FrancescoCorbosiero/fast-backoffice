<?php

declare(strict_types=1);

namespace App\Modules\Mail\Queries;

use App\Modules\Mail\Data\CampaignData;
use App\Modules\Mail\Data\RecipientData;
use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Models\Campaign;
use App\Modules\Mail\Models\CampaignRecipient;
use Carbon\CarbonImmutable;

final readonly class Campaigns
{
    public function find(int $id): ?CampaignData
    {
        return Campaign::query()->find($id)?->toData();
    }

    /**
     * Scheduled campaigns whose time has come.
     *
     * @return list<CampaignData>
     */
    public function dueToStart(CarbonImmutable $now): array
    {
        return array_values(Campaign::query()->where('status', CampaignStatus::Scheduled->value)->where('scheduled_at', '<=', $now)->orderBy('scheduled_at')->get()
            ->map(fn (Campaign $c): CampaignData => $c->toData())->all());
    }

    /**
     * @return list<RecipientData>
     */
    public function recipients(int $campaignId): array
    {
        return array_values(CampaignRecipient::query()->where('campaign_id', $campaignId)->orderBy('id')->get()
            ->map(fn (CampaignRecipient $r): RecipientData => $r->toData())->all());
    }

    /**
     * What a contact received, newest campaign first.
     *
     * @return list<RecipientData>
     */
    public function forContact(int $contactId): array
    {
        return array_values(CampaignRecipient::query()->where('contact_id', $contactId)->orderByDesc('id')->get()
            ->map(fn (CampaignRecipient $r): RecipientData => $r->toData())->all());
    }
}
