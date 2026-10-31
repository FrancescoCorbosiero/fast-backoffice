<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Typed reads from loosely shaped arrays (JSON settings, form state), so
 * DTOs can be built from them under Larastan's max level without a cast
 * on every line.
 */
final class Shape
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : $default);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = self::string($data, $key);

        return trim($value) === '' ? null : $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1 ? (int) $value : $default;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : (is_int($value) ? $value !== 0 : $default);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    public static function listOfArrays(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        $list = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_array($item)) {
                    $list[] = $item;
                }
            }
        }

        return $list;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<int, int>
     */
    public static function intMap(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        $map = [];

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_numeric($k) && (is_int($v) || (is_string($v) && preg_match('/^\d+$/', $v) === 1))) {
                    $map[(int) $k] = (int) $v;
                }
            }
        }

        ksort($map);

        return $map;
    }
}
