<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Integrations;

/**
 * Cal.com signs each delivery: X-Cal-Signature-256 is the hex HMAC-SHA256
 * of the raw body with the webhook's secret.
 */
final class WebhookSignature
{
    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    public static function verify(string $body, ?string $header, ?string $secret): bool
    {
        if ($secret === null || trim($secret) === '' || $header === null) {
            return false;
        }

        $given = strtolower(trim(str_replace('sha256=', '', $header)));

        return hash_equals(self::sign($body, $secret), $given);
    }
}
