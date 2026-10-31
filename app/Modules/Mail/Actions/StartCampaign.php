<?php

declare(strict_types=1);

namespace App\Modules\Mail\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Queries\SearchContacts;
use App\Modules\Mail\Data\CampaignData;
use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Events\CampaignStarted;
use App\Modules\Mail\Jobs\SendCampaignEmail;
use App\Modules\Mail\Models\Campaign;
use App\Modules\Mail\Models\CampaignRecipient;
use App\Modules\Settings\Queries\GetMailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Sends now, or schedules. The audience is frozen at start: every mailable
 * contact (consent respected) in the chosen roles, one row each, one
 * queued job each. "Sending" means queued, not sent (hive-crm's lesson):
 * the campaign is "sent" only when every recipient reached a final state.
 */
final readonly class StartCampaign
{
    public function __construct(
        private ConnectionInterface $db,
        private SearchContacts $contacts,
        private GetMailSettings $settings,
        private Bus $bus,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    /**
     * @throws ValidationException when the sender is not configured, or nobody can receive it
     * @throws LogicException when the campaign already went out
     */
    public function start(int $campaignId, ?CarbonImmutable $scheduledAt = null): CampaignData
    {
        if (! $this->settings->get()->isConfigured()) {
            throw ValidationException::withMessages(['from_address' => 'Imposta il mittente in Impostazioni › Campagne email.']);
        }

        if ($scheduledAt !== null && $scheduledAt->greaterThan(Date::now())) {
            $campaign = Campaign::query()->findOrFail($campaignId);
            if (! $campaign->status->isEditable()) {
                throw new LogicException("Campaign {$campaignId} is {$campaign->status->value}.");
            }
            $campaign->forceFill(['status' => CampaignStatus::Scheduled, 'scheduled_at' => $scheduledAt])->save();
            $this->audit->record('mail.campaign.scheduled', $campaign->toData()->reference(), ['at' => $scheduledAt->toIso8601String()]);

            return $campaign->toData();
        }

        $recipientIds = $this->db->transaction(function () use ($campaignId): array {
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);

            if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true)) {
                throw new LogicException("Campaign {$campaignId} is {$campaign->status->value}: it was already started.");
            }

            $audience = $this->contacts->mailable($campaign->roleEnums());
            if ($audience === []) {
                throw ValidationException::withMessages(['audience_roles' => 'Nessun contatto può ricevere questa campagna (serve email e consenso).']);
            }

            $ids = [];
            $seen = [];
            foreach ($audience as $contact) {
                $email = mb_strtolower((string) $contact->email);
                if (isset($seen[$email])) {
                    continue;
                }
                $seen[$email] = true;
                $ids[] = $this->recipientFor($campaign, $contact, $email)->id;
            }

            $campaign->forceFill(['status' => CampaignStatus::Sending, 'started_at' => Date::now(), 'scheduled_at' => null])->save();
            $this->audit->record('mail.campaign.started', $campaign->toData()->reference(), ['recipients' => count($ids)]);
            $this->events->dispatch(new CampaignStarted($campaign->id, count($ids)));

            return $ids;
        });

        foreach ($recipientIds as $id) {
            $this->bus->dispatch(new SendCampaignEmail($id));
        }

        return Campaign::query()->findOrFail($campaignId)->toData();
    }

    private function recipientFor(Campaign $campaign, ContactData $contact, string $email): CampaignRecipient
    {
        $existing = CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('contact_id', $contact->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        return CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $email,
            'status' => RecipientStatus::Queued,
            'unsubscribe_token' => Str::random(48),
        ]);
    }
}
