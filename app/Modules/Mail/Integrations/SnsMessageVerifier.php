<?php

declare(strict_types=1);

namespace App\Modules\Mail\Integrations;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Checks an SNS message's signature (SignatureVersion 1, SHA1, or 2,
 * SHA256) with the certificate SNS points at — only from an amazonaws.com
 * host, over https. Certificates are cached for an hour.
 */
final readonly class SnsMessageVerifier
{
    private const string CERT_HOST = '/^sns\.[a-z0-9\-]+\.amazonaws\.com(\.cn)?$/';

    public function __construct(
        private Factory $http,
        private Cache $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     */
    public function verify(array $message): bool
    {
        $type = $this->string($message, 'Type');
        $signature = $this->string($message, 'Signature');
        $certUrl = $this->string($message, 'SigningCertURL');
        if ($type === null || $signature === null || $certUrl === null || ! $this->isTrustedCertUrl($certUrl)) {
            return false;
        }

        $canonical = $this->canonical($message, $type);
        if ($canonical === null) {
            return false;
        }

        $certificate = $this->certificate($certUrl);
        if ($certificate === null) {
            return false;
        }

        $decoded = base64_decode($signature, true);
        if ($decoded === false) {
            return false;
        }

        $algorithm = $this->string($message, 'SignatureVersion') === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
        $key = openssl_pkey_get_public($certificate);
        if ($key === false) {
            return false;
        }

        return openssl_verify($canonical, $decoded, $key, $algorithm) === 1;
    }

    public function isTrustedCertUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && isset($parts['host']) && preg_match(self::CERT_HOST, $parts['host']) === 1
            && str_ends_with((string) ($parts['path'] ?? ''), '.pem');
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function canonical(array $message, string $type): ?string
    {
        $keys = match ($type) {
            'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
            'SubscriptionConfirmation', 'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
            default => null,
        };
        if ($keys === null) {
            return null;
        }

        $canonical = '';
        foreach ($keys as $key) {
            $value = $message[$key] ?? null;
            if ($value === null && $key === 'Subject') {
                continue;
            }
            if (! is_scalar($value)) {
                return null;
            }
            $canonical .= $key."\n".$value."\n";
        }

        return $canonical;
    }

    private function certificate(string $url): ?string
    {
        $cached = $this->cache->get('sns-cert:'.sha1($url));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->http->timeout(10)->get($url);
        } catch (Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        $pem = $response->body();
        if (! str_contains($pem, 'BEGIN CERTIFICATE')) {
            return null;
        }

        $this->cache->put('sns-cert:'.sha1($url), $pem, 3600);

        return $pem;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function string(array $message, string $key): ?string
    {
        $value = $message[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
