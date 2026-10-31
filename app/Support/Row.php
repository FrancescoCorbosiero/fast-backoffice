<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Typed reads from the rows a raw query returns (stdClass objects).
 */
final class Row
{
    public static function int(object $row, string $key): int
    {
        $value = $row->{$key} ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function nullableInt(object $row, string $key): ?int
    {
        $value = $row->{$key} ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public static function string(object $row, string $key): string
    {
        $value = $row->{$key} ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
