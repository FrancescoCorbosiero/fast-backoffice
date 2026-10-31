<?php

declare(strict_types=1);

namespace App\Kernel\Fiscal;

/**
 * The Italian VAT number: 11 digits, the last one a Luhn-style check digit.
 */
final class PartitaIva
{
    /**
     * Upper-cases, strips spaces and a leading "IT".
     */
    public static function normalise(string $value): string
    {
        $value = strtoupper(preg_replace('/\s+/', '', $value) ?? '');

        return str_starts_with($value, 'IT') ? substr($value, 2) : $value;
    }

    public static function isValid(string $value): bool
    {
        $value = self::normalise($value);

        if (preg_match('/^\d{11}$/', $value) !== 1) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $value[$i];

            if ($i % 2 === 0) {
                $sum += $digit;
            } else {
                $doubled = $digit * 2;
                $sum += $doubled > 9 ? $doubled - 9 : $doubled;
            }
        }

        return (10 - $sum % 10) % 10 === (int) $value[10];
    }
}
