<?php

declare(strict_types=1);

namespace App\Kernel\Fiscal;

/**
 * The Italian tax code: 16 characters with a checksum for people (also
 * in the "omocodia" variant, where digits become letters), or 11 digits
 * with the VAT check digit for companies and other bodies.
 */
final class CodiceFiscale
{
    private const string PATTERN = '/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/';

    private const array ODD = [
        '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
        'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
        'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
        'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
    ];

    public static function normalise(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
    }

    public static function isValid(string $value): bool
    {
        $value = self::normalise($value);

        if (preg_match('/^\d{11}$/', $value) === 1) {
            return PartitaIva::isValid($value);
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            return false;
        }

        return self::checkCharacter(substr($value, 0, 15)) === $value[15];
    }

    /**
     * The 16th character computed from the first 15.
     */
    public static function checkCharacter(string $first15): string
    {
        $sum = 0;

        for ($i = 0; $i < 15; $i++) {
            $character = $first15[$i];

            // Positions are counted from 1: the first character is odd.
            $sum += $i % 2 === 0
                ? self::ODD[$character]
                : (ctype_digit($character) ? (int) $character : ord($character) - ord('A'));
        }

        return chr(ord('A') + $sum % 26);
    }
}
