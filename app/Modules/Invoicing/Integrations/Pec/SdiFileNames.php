<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Integrations\Pec;

/**
 * What SdI calls its files (sdi.md §5): an invoice IT…_00001.xml (or
 * .xml.p7m), its notifications IT…_00001_RC_001.xml, the metadata
 * IT…_00001_MT_001.xml. **verify** against real notifications.
 */
final class SdiFileNames
{
    /**
     * @return array{invoice: string, code: string}|null the invoice file name the notification is about, and the type
     */
    public static function notification(string $name): ?array
    {
        if (preg_match('/^([A-Z]{2}[A-Z0-9]{1,28}_[A-Z0-9]{1,5})_([A-Z]{2})_\d{3}\.xml$/i', $name, $m) !== 1) {
            return null;
        }

        return ['invoice' => $m[1].'.xml', 'code' => strtoupper($m[2])];
    }

    public static function isMetadata(string $name): bool
    {
        $parsed = self::notification($name);

        return $parsed !== null && $parsed['code'] === 'MT';
    }

    public static function isInvoice(string $name): bool
    {
        return preg_match('/^[A-Z]{2}[A-Z0-9]{1,28}_[A-Z0-9]{1,5}\.xml(\.p7m)?$/i', $name) === 1;
    }

    /**
     * IT…_00001.xml.p7m → IT…_00001.xml
     */
    public static function plain(string $name): string
    {
        return preg_replace('/\.p7m$/i', '', $name) ?? $name;
    }
}
