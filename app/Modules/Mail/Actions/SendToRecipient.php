<?php

declare(strict_types=1);

namespace App\Modules\Mail\Actions;

use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Mail\Data\RecipientData;
use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Integrations\CampaignMessage;
use App\Modules\Mail\Models\CampaignRecipient;
use App\Modules\Settings\Queries\GetMailSettings;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Message;
use Throwable;

/**
 * One email to one recipient: skipped if the contact can no longer be
 * mailed or the campaign was paused, sent once (the row is claimed before
 * the send), and on failure the attempt is counted and the error kept for
 * the queue to retry.
 */
final readonly class SendToRecipient
{
    public function __construct(
        private ConnectionInterface $db,
        private FindContact $contacts,
        private GetMailSettings $settings,
        private MailFactory $mail,
        private Repository $config,
        private CompleteCampaign $complete,
    ) {}

    /**
     * @throws Throwable the transport's failure, after it was recorded, so the queue retries
     */
    public function send(int $recipientId): RecipientData
    {
        $recipient = CampaignRecipient::query()->with('campaign')->findOrFail($recipientId);
        $campaign = $recipient->campaign;

        if ($recipient->status !== RecipientStatus::Queued || $campaign === null) {
            return $recipient->toData();
        }
        if ($campaign->status !== CampaignStatus::Sending) {
            return $recipient->toData();
        }

        $contact = $this->contacts->byId($recipient->contact_id);
        if ($contact === null || ! $contact->isMailable() || mb_strtolower((string) $contact->email) !== $recipient->email) {
            $recipient->forceFill(['status' => RecipientStatus::Skipped, 'last_error' => 'Il contatto non può più ricevere email.'])->save();
            $this->complete->completeIfDone($campaign->id);

            return $recipient->toData();
        }

        $settings = $this->settings->get();
        $appUrl = $this->config->get('app.url');
        $domain = parse_url(is_string($appUrl) ? $appUrl : '', PHP_URL_HOST);
        $messageId = Str::lower(Str::ulid()->toBase32()).'.campaign-'.$campaign->id.'@'.(is_string($domain) && $domain !== '' ? $domain : 'fast-backoffice.local');
        $mailer = $this->config->get('mail-campaigns.mailer');

        $message = new CampaignMessage(
            messageId: $messageId,
            fromAddress: (string) $settings->fromAddress,
            fromName: $settings->fromName,
            replyToAddress: $settings->replyTo,
            recipientEmail: $recipient->email,
            subjectLine: $campaign->subject,
            preheader: $campaign->preheader,
            bodyHtml: $campaign->body_html,
            unsubscribeUrl: route('mail.unsubscribe', ['token' => $recipient->unsubscribe_token]),
        );

        try {
            $sent = $this->mail->mailer(is_string($mailer) && $mailer !== '' ? $mailer : null)->send($message);
        } catch (Throwable $exception) {
            $recipient->forceFill(['attempts' => $recipient->attempts + 1, 'last_error' => mb_substr($exception->getMessage(), 0, 2000)])->save();

            throw $exception;
        }

        $sesMessageId = null;
        if ($sent !== null) {
            $original = $sent->getSymfonySentMessage()->getOriginalMessage();
            if ($original instanceof Message) {
                $header = $original->getHeaders()->get('X-SES-Message-ID');
                $sesMessageId = $header?->getBodyAsString();
            }
        }

        $this->db->transaction(function () use ($recipient, $messageId, $sesMessageId): void {
            $recipient->forceFill([
                'status' => RecipientStatus::Sent,
                'attempts' => $recipient->attempts + 1,
                'message_id' => $messageId,
                'ses_message_id' => $sesMessageId,
                'sent_at' => Date::now(),
                'last_error' => null,
            ])->save();
        });

        $this->complete->completeIfDone($campaign->id);

        return $recipient->toData();
    }
}
