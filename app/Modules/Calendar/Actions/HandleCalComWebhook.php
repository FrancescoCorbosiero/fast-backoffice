<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Actions;

use App\Modules\Calendar\Data\WebhookResult;
use App\Modules\Calendar\Integrations\CalComPayload;
use App\Modules\Calendar\Integrations\WebhookSignature;
use App\Modules\Calendar\Models\WebhookEvent;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * A delivery from Cal.com: refused unless signed with the webhook secret,
 * handled once (the body's hash is the idempotency key), and turned into
 * a booking for the triggers that carry one.
 */
final readonly class HandleCalComWebhook
{
    private const array BOOKING_TRIGGERS = ['BOOKING_CREATED', 'BOOKING_RESCHEDULED', 'BOOKING_CANCELLED', 'BOOKING_REJECTED', 'BOOKING_REQUESTED', 'BOOKING_PAID', 'BOOKING_PAYMENT_INITIATED', 'BOOKING_NO_SHOW_UPDATED'];

    public function __construct(
        private ConnectionInterface $db,
        private Repository $config,
        private RecordBooking $record,
    ) {}

    public function handle(string $rawBody, ?string $signature): WebhookResult
    {
        $secret = $this->config->get('calcom.webhook_secret');
        if (! WebhookSignature::verify($rawBody, $signature, is_string($secret) ? $secret : null)) {
            return new WebhookResult('rejected', note: 'Firma mancante o non valida.');
        }

        $key = hash('sha256', $rawBody);
        if (WebhookEvent::query()->where('idempotency_key', $key)->exists()) {
            return new WebhookResult('duplicate');
        }

        $body = json_decode($rawBody, true);
        $trigger = is_array($body) && is_string($body['triggerEvent'] ?? null) ? strtoupper($body['triggerEvent']) : null;

        if (! is_array($body)) {
            return $this->store($key, $trigger, 'ignored', 'Il corpo non è JSON.');
        }
        if ($trigger === 'PING' || $trigger === null || ! in_array($trigger, self::BOOKING_TRIGGERS, true)) {
            return $this->store($key, $trigger, 'ignored', 'Evento non gestito: '.($trigger ?? '—').'.');
        }

        try {
            $input = CalComPayload::fromWebhook($body);
        } catch (InvalidArgumentException $exception) {
            return $this->store($key, $trigger, 'ignored', $exception->getMessage());
        }

        $booking = $this->record->record($input, 'webhook');

        return $this->store($key, $trigger, 'processed', null, $booking->id);
    }

    private function store(string $key, ?string $trigger, string $outcome, ?string $note, ?int $bookingId = null): WebhookResult
    {
        // A savepoint: a duplicate key must not poison an enclosing transaction.
        try {
            $this->db->transaction(fn () => WebhookEvent::query()->create([
                'idempotency_key' => $key,
                'trigger' => $trigger === null ? null : mb_substr($trigger, 0, 60),
                'outcome' => $outcome,
                'note' => $note === null ? null : mb_substr($note, 0, 500),
                'booking_id' => $bookingId,
                'received_at' => Date::now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return new WebhookResult('duplicate', $bookingId);
        }

        return new WebhookResult($outcome, $bookingId, $note);
    }
}
