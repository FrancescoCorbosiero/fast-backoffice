<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSSLCertificateSigningRequest;
use RuntimeException;

/**
 * SNS messages signed with a throwaway key whose certificate the test
 * serves from an amazonaws.com URL (through Http::fake).
 */
final class SnsFixtures
{
    public const string CERT_URL = 'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-test.pem';

    public const string TOPIC = 'arn:aws:sns:eu-west-1:123456789012:ses-events';

    private static ?string $privateKey = null;

    private static ?string $certificate = null;

    public static function certificate(): string
    {
        self::keys();

        return (string) self::$certificate;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function sign(array $fields, int $version = 2): array
    {
        self::keys();
        $type = is_string($fields['Type'] ?? null) ? $fields['Type'] : '';
        $keys = $type === 'Notification' ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'] : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $canonical = '';
        foreach ($keys as $key) {
            $value = $fields[$key] ?? null;
            if (! is_scalar($value)) {
                continue;
            }
            $canonical .= $key."\n".$value."\n";
        }

        $signature = '';
        if (! openssl_sign($canonical, $signature, (string) self::$privateKey, $version === 2 ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1) || ! is_string($signature)) {
            throw new RuntimeException('Could not sign the fixture.');
        }

        return [...$fields, 'SignatureVersion' => (string) $version, 'Signature' => base64_encode($signature), 'SigningCertURL' => self::CERT_URL, 'UnsubscribeURL' => 'https://sns.eu-west-1.amazonaws.com/?Action=Unsubscribe'];
    }

    /**
     * @param  array<string, mixed>  $sesEvent
     * @return array<string, mixed>
     */
    public static function notification(array $sesEvent, ?string $messageId = null): array
    {
        return self::sign([
            'Type' => 'Notification',
            'MessageId' => $messageId ?? bin2hex(random_bytes(16)),
            'TopicArn' => self::TOPIC,
            'Message' => json_encode($sesEvent, JSON_THROW_ON_ERROR),
            'Timestamp' => '2026-03-15T10:05:00.000Z',
        ]);
    }

    /**
     * @param  list<string>  $recipients
     * @return array<string, mixed>
     */
    public static function sesEvent(string $type, string $headerMessageId, array $recipients, ?string $bounceType = null, string $sesMessageId = 'ses-0123456789'): array
    {
        $event = [
            'eventType' => $type,
            'mail' => [
                'timestamp' => '2026-03-15T10:00:00.000Z',
                'messageId' => $sesMessageId,
                'source' => 'studio@example.test',
                'destination' => $recipients,
                'commonHeaders' => ['from' => ['Studio <studio@example.test>'], 'to' => $recipients, 'messageId' => '<'.$headerMessageId.'>', 'subject' => 'Test'],
            ],
        ];
        $wrap = fn (string $key, string $address): array => [$key => $address];

        return match ($type) {
            'Bounce' => [...$event, 'bounce' => ['bounceType' => $bounceType ?? 'Permanent', 'bounceSubType' => $bounceType === 'Transient' ? 'MailboxFull' : 'General', 'bouncedRecipients' => array_map(fn (string $r): array => $wrap('emailAddress', $r), $recipients), 'timestamp' => '2026-03-15T10:04:00.000Z']],
            'Complaint' => [...$event, 'complaint' => ['complainedRecipients' => array_map(fn (string $r): array => $wrap('emailAddress', $r), $recipients), 'timestamp' => '2026-03-15T10:04:00.000Z', 'complaintFeedbackType' => 'abuse']],
            'Delivery' => [...$event, 'delivery' => ['recipients' => $recipients, 'timestamp' => '2026-03-15T10:04:00.000Z', 'smtpResponse' => '250 OK']],
            default => $event,
        };
    }

    private static function keys(): void
    {
        if (self::$privateKey !== null) {
            return;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('No key.');
        }
        $keyForCsr = $key;
        $csr = openssl_csr_new(['commonName' => 'sns.eu-west-1.amazonaws.com'], $keyForCsr);
        if (! $csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('No CSR.');
        }
        $cert = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256']);
        if ($cert === false) {
            throw new RuntimeException('No certificate.');
        }
        $pem = '';
        $private = '';
        openssl_x509_export($cert, $pem);
        openssl_pkey_export($key, $private);
        if (! is_string($pem) || ! is_string($private)) {
            throw new RuntimeException('Could not export the key pair.');
        }
        self::$certificate = $pem;
        self::$privateKey = $private;
    }
}
