<?php

declare(strict_types=1);

namespace App\Modules\Mail\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Mail\Data\RecipientData;
use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Jobs\SendCampaignEmail;
use App\Modules\Mail\Models\CampaignRecipient;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * A recipient whose retry budget ran out (the transport was down for the
 * night) gets another go: queued again, the error cleared, a fresh job.
 * A campaign already closed as sent reopens to sending until this one
 * reaches a final state again.
 */
final readonly class RequeueRecipient
{
    public function __construct(
        private ConnectionInterface $db,
        private Bus $bus,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * @throws LogicException unless the recipient failed
     */
    public function requeue(int $recipientId): RecipientData
    {
        $recipient = $this->db->transaction(function () use ($recipientId): CampaignRecipient {
            $recipient = CampaignRecipient::query()->with('campaign')->lockForUpdate()->findOrFail($recipientId);
            $campaign = $recipient->campaign;

            if ($recipient->status !== RecipientStatus::Failed || $campaign === null) {
                throw new LogicException("Recipient {$recipientId} is {$recipient->status->value}: only a failed send is retried.");
            }
            if (! in_array($campaign->status, [CampaignStatus::Sending, CampaignStatus::Paused, CampaignStatus::Sent], true)) {
                throw new LogicException("Campaign {$campaign->id} is {$campaign->status->value}: nothing more goes out.");
            }

            $recipient->forceFill(['status' => RecipientStatus::Queued, 'last_error' => null])->save();
            if ($campaign->status === CampaignStatus::Sent) {
                $campaign->forceFill(['status' => CampaignStatus::Sending, 'completed_at' => null])->save();
            }
            $this->audit->record('mail.recipient.requeued', $campaign->toData()->reference(), ['recipient' => $recipient->id, 'email' => $recipient->email]);

            return $recipient;
        });

        $this->bus->dispatch(new SendCampaignEmail($recipient->id));

        return $recipient->toData();
    }
}
