<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Integrations\FatturaPa;

use InvalidArgumentException;

/**
 * Supplier invoices may arrive CAdES-signed (.xml.p7m): the XML sits inside
 * a CMS SignedData envelope, DER or base64. The signature is not checked
 * here — SdI did that — only removed.
 */
final class SignedFile
{
    public static function isSigned(string $fileName): bool
    {
        return (bool) preg_match('/\.p7m$/i', $fileName);
    }

    /**
     * @throws InvalidArgumentException when no XML can be found inside
     */
    public static function unwrap(string $contents): string
    {
        $der = $contents;
        if (! str_starts_with($der, "\x30")) {
            $decoded = base64_decode(preg_replace('/\s+/', '', $der) ?? '', true);
            if ($decoded !== false && str_starts_with($decoded, "\x30")) {
                $der = $decoded;
            }
        }

        $xml = self::viaOpenssl($der) ?? self::viaScan($der);

        if ($xml === null) {
            throw new InvalidArgumentException('Il file firmato non contiene una fattura elettronica leggibile.');
        }

        return $xml;
    }

    private static function viaOpenssl(string $der): ?string
    {
        if (! function_exists('openssl_cms_verify')) {
            return null;
        }

        $input = tempnam(sys_get_temp_dir(), 'p7m');
        $output = tempnam(sys_get_temp_dir(), 'xml');
        if ($input === false || $output === false) {
            return null;
        }

        try {
            file_put_contents($input, $der);
            $ok = @openssl_cms_verify($input, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_NOSIGS, null, [], null, $output, null, null, OPENSSL_ENCODING_DER);
            $content = $ok ? (string) file_get_contents($output) : '';

            return $content !== '' && str_contains($content, 'FatturaElettronica') ? $content : null;
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }

    /**
     * Last resort: the XML as it sits in the DER, when the content is one octet string.
     */
    private static function viaScan(string $der): ?string
    {
        $start = strpos($der, '<?xml');
        if ($start === false) {
            $start = strpos($der, '<');
        }
        if ($start === false) {
            return null;
        }

        $end = strrpos($der, 'FatturaElettronica>');
        if ($end === false || $end < $start) {
            return null;
        }

        return substr($der, $start, $end + strlen('FatturaElettronica>') - $start);
    }
}
