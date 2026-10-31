<?php

declare(strict_types=1);

namespace App\Modules\Mail\Actions;

use App\Modules\Contacts\Actions\SuppressEmail;
use App\Modules\Mail\Data\SesEvent;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Events\RecipientBounced;
use App\Modules\Mail\Integrations\SesEventParser;
use App\Modules\Mail\Integrations\SnsMessageVerifier;
use App\Modules\Mail\Models\CampaignRecipient;
use App\Modules\Mail\Models\MailEvent;
use App\Modules\Settings\Queries\GetMailSettings;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * An SNS delivery about SES: refused unless its signature checks out and
 * it comes from our topic; handled once (SNS's MessageId is the key); a
 * subscription confirmed by visiting its URL; an event applied to the
 * recipient it is about with the bounce policy (design.md §4.2, Mail):
 * permanent bounce or complaint → suppress; temporary → count, suppress
 * after repeated failures.
 */
final readonly class ProcessSesNotification
{
    public function __construct(
        private ConnectionInterface $db,
        private SnsMessageVerifier $verifier,
        private SesEventParser $parser,
        private Repository $config,
        private Factory $http,
        private SuppressEmail $suppress,
        private GetMailSettings $settings,
        private Dispatcher $events,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     * @return string one of rejected, duplicate, confirmed, processed, ignored
     */
    public function process(array $message): string
    {
        if (! $this->verifier->verify($message)) {
            return 'rejected';
        }

        $expectedTopic = $this->config->get('mail-campaigns.sns_topic_arn');
        $topic = $message['TopicArn'] ?? null;
        if (is_string($expectedTopic) && $expectedTopic !== '' && $topic !== $expectedTopic) {
            return 'rejected';
        }

        $snsId = is_string($message['MessageId'] ?? null) ? $message['MessageId'] : hash('sha256', json_encode($message) ?: '');
        $type = $message['Type'] ?? null;

        if ($type === 'SubscriptionConfirmation') {
            $url = $message['SubscribeURL'] ?? null;
            if (is_string($url) && $this->verifier->isTrustedCertUrl(preg_replace('/\?.*$/', '.pem', $url) ?? '')) {
                try {
                    $this->http->timeout(10)->get($url);
                } catch (Throwable) {
                    // SNS retries the confirmation; nothing to record.
                }
            }

            return $this->store($snsId, 'SubscriptionConfirmation', 'confirmed', null, null, null, null) ?? 'duplicate';
        }

        if ($type !== 'Notification') {
            return $this->store($snsId, is_string($type) ? $type : null, 'ignored', 'Tipo non gestito.', null, null, null) ?? 'duplicate';
        }

        $event = is_string($message['Message'] ?? null) ? $this->parser->parse($message['Message']) : null;
        if ($event === null) {
            return $this->store($snsId, null, 'ignored', 'Il messaggio non è un evento SES.', null, null, null) ?? 'duplicate';
        }

        $recipient = $this->match($event);
        $outcome = $this->store($snsId, $event->type, $recipient === null ? 'ignored' : 'processed', $recipient === null ? 'Nessun destinatario corrisponde al messaggio.' : null, $recipient?->id, $event, $message);
        if ($outcome === null) {
            return 'duplicate';
        }

        if ($recipient !== null) {
            $this->apply($event, $recipient);
        }

        return $outcome;
    }

    private function match(SesEvent $event): ?CampaignRecipient
    {
        if ($event->headerMessageId !== null) {
            $recipient = CampaignRecipient::query()->where('message_id', $event->headerMessageId)->first();
            if ($recipient !== null) {
                return $recipient;
            }
        }
        if ($event->sesMessageId !== null) {
            return CampaignRecipient::query()->where('ses_message_id', $event->sesMessageId)->orderByDesc('id')->first();
        }

        return null;
    }

    private function apply(SesEvent $event, CampaignRecipient $recipient): void
    {
        $at = $event->occurredAt ?? Date::now();

        switch ($event->type) {
            case 'Delivery':
                if (in_array($recipient->status, [RecipientStatus::Sent, RecipientStatus::SoftBounced], true)) {
                    $recipient->forceFill(['status' => RecipientStatus::Delivered, 'delivered_at' => $at])->save();
                }
                break;

            case 'Bounce':
                if ($event->isPermanentBounce()) {
                    $recipient->forceFill(['status' => RecipientStatus::Bounced, 'bounced_at' => $at, 'last_error' => trim('Bounce '.($event->bounceSubType ?? ''))])->save();
                    $this->suppress->suppress($recipient->contact_id, 'bounce');
                } else {
                    $recipient->forceFill(['status' => RecipientStatus::SoftBounced, 'bounced_at' => $at, 'last_error' => trim('Bounce temporaneo '.($event->bounceSubType ?? ''))])->save();
                    $soft = CampaignRecipient::query()->where('contact_id', $recipient->contact_id)->where('status', RecipientStatus::SoftBounced->value)->count();
                    if ($soft >= $this->settings->get()->transientBouncesBeforeSuppression) {
                        $this->suppress->suppress($recipient->contact_id, 'bounce');
                    }
                }
                $this->events->dispatch(new RecipientBounced($recipient->id, $recipient->contact_id, $event->isPermanentBounce()));
                break;

            case 'Complaint':
                $recipient->forceFill(['status' => RecipientStatus::Complained, 'last_error' => 'Segnalata come spam'])->save();
                $this->suppress->suppress($recipient->contact_id, 'complaint');
                break;

            default:
                // Send, Open, Click, Reject…: on record, nothing to change.
        }
    }

    /**
     * @param  ?array<string, mixed>  $message
     * @return ?string the outcome, or null when this SNS message was already seen
     */
    private function store(string $snsId, ?string $type, string $outcome, ?string $note, ?int $recipientId, ?SesEvent $event, ?array $message): ?string
    {
        // A savepoint: a duplicate key must not poison an enclosing transaction.
        try {
            $this->db->transaction(fn () => MailEvent::query()->create([
                'sns_message_id' => mb_substr($snsId, 0, 120),
                'event_type' => $type === null ? null : mb_substr($type, 0, 40),
                'ses_message_id' => $event?->sesMessageId,
                'header_message_id' => $event?->headerMessageId,
                'recipient_id' => $recipientId,
                'outcome' => $outcome,
                'note' => $note,
                'payload' => $event !== null ? $event->payload : ($message === null ? null : ['Type' => $message['Type'] ?? null]),
                'received_at' => Date::now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $outcome;
    }
}
