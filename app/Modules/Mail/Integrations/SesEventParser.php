<?php

declare(strict_types=1);

namespace App\Modules\Mail\Integrations;

use App\Modules\Mail\Data\SesEvent;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads an SES event out of the SNS message body: the newer event
 * publishing format (eventType) and the older notifications
 * (notificationType) both. Our Message-ID header comes back in
 * mail.commonHeaders.messageId, which is how a recipient is found.
 */
final class SesEventParser
{
    public function parse(string $json): ?SesEvent
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }

        $type = $data['eventType'] ?? $data['notificationType'] ?? null;
        if (! is_string($type) || $type === '') {
            return null;
        }

        $mail = is_array($data['mail'] ?? null) ? $data['mail'] : [];
        $common = is_array($mail['commonHeaders'] ?? null) ? $mail['commonHeaders'] : [];
        $headerMessageId = is_string($common['messageId'] ?? null) ? trim($common['messageId'], " <>\t") : null;
        if ($headerMessageId === null && is_array($mail['headers'] ?? null)) {
            foreach ($mail['headers'] as $header) {
                $name = is_array($header) ? ($header['name'] ?? null) : null;
                if (is_array($header) && is_string($name) && strcasecmp($name, 'Message-ID') === 0 && is_string($header['value'] ?? null)) {
                    $headerMessageId = trim($header['value'], " <>\t");
                }
            }
        }

        $recipients = [];
        $section = match ($type) {
            'Bounce' => $data['bounce'] ?? null,
            'Complaint' => $data['complaint'] ?? null,
            'Delivery' => $data['delivery'] ?? null,
            default => null,
        };
        $list = is_array($section) ? ($section['bouncedRecipients'] ?? $section['complainedRecipients'] ?? $section['recipients'] ?? null) : null;
        if (is_array($list)) {
            foreach ($list as $item) {
                $address = is_array($item) ? ($item['emailAddress'] ?? null) : $item;
                if (is_string($address) && $address !== '') {
                    $recipients[] = mb_strtolower(trim($address));
                }
            }
        }
        if ($recipients === [] && is_array($mail['destination'] ?? null)) {
            foreach ($mail['destination'] as $address) {
                if (is_string($address)) {
                    $recipients[] = mb_strtolower(trim($address));
                }
            }
        }

        $occurredAt = null;
        $timestamp = is_array($section) && is_string($section['timestamp'] ?? null) ? $section['timestamp'] : ($mail['timestamp'] ?? null);
        if (is_string($timestamp)) {
            try {
                $occurredAt = CarbonImmutable::parse($timestamp);
            } catch (Throwable) {
                $occurredAt = null;
            }
        }

        $bounce = is_array($data['bounce'] ?? null) ? $data['bounce'] : [];
        $payload = [];
        foreach ($data as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return new SesEvent(
            type: $type,
            sesMessageId: is_string($mail['messageId'] ?? null) ? $mail['messageId'] : null,
            headerMessageId: $headerMessageId,
            bounceType: is_string($bounce['bounceType'] ?? null) ? $bounce['bounceType'] : null,
            bounceSubType: is_string($bounce['bounceSubType'] ?? null) ? $bounce['bounceSubType'] : null,
            recipients: array_values(array_unique($recipients)),
            occurredAt: $occurredAt,
            payload: $payload,
        );
    }
}
