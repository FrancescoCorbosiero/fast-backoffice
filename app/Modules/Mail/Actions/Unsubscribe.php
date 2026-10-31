<?php

declare(strict_types=1);

namespace App\Modules\Mail\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Contacts\Actions\SetEmailConsent;
use App\Modules\Contacts\Actions\SuppressEmail;
use App\Modules\Mail\Data\RecipientData;
use App\Modules\Mail\Models\CampaignRecipient;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;

/**
 * RFC 8058 one-click unsubscribe, and the confirmation page's button: the
 * recipient's token names the contact; consent goes, the address is
 * suppressed, nothing else is asked. Only ever on POST: a link scanner
 * following the GET must not unsubscribe anyone (hive-crm's lesson).
 */
final readonly class Unsubscribe
{
    public function __construct(
        private ConnectionInterface $db,
        private SetEmailConsent $consent,
        private SuppressEmail $suppress,
        private RecordAuditEntry $audit,
    ) {}

    public function find(string $token): ?RecipientData
    {
        return CampaignRecipient::query()->where('unsubscribe_token', $token)->first()?->toData();
    }

    /**
     * @return ?RecipientData null when the token is unknown
     */
    public function unsubscribe(string $token): ?RecipientData
    {
        return $this->db->transaction(function () use ($token): ?RecipientData {
            $recipient = CampaignRecipient::query()->lockForUpdate()->where('unsubscribe_token', $token)->first();
            if ($recipient === null) {
                return null;
            }

            if ($recipient->unsubscribed_at === null) {
                $recipient->forceFill(['unsubscribed_at' => Date::now()])->save();
            }

            $this->consent->set($recipient->contact_id, false, 'unsubscribe');
            $this->suppress->suppress($recipient->contact_id, 'unsubscribe');
            $this->audit->record('mail.unsubscribed', $recipient->campaign?->toData()->reference(), ['contact_id' => $recipient->contact_id, 'email' => $recipient->email]);

            return $recipient->toData();
        });
    }
}
