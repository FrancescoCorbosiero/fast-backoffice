<?php

declare(strict_types=1);

namespace App\Kernel\Money;

use InvalidArgumentException;
use Stringable;

/**
 * A quantity with four decimals, stored as an integer scaled by 10 000
 * (1.5 → 15 000). Negative quantities are discount lines.
 */
final readonly class Quantity implements Stringable
{
    public const int SCALE = 10_000;

    public function __construct(public int $units) {}

    public static function of(int $whole): self
    {
        return new self($whole * self::SCALE);
    }

    /**
     * Parse "2", "1.5", "0,25" or "-1.1234" (at most four decimals).
     */
    public static function fromDecimal(string $quantity): self
    {
        $normalised = str_replace(',', '.', trim($quantity));

        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,4}))?$/', $normalised, $parts) !== 1) {
            throw new InvalidArgumentException("\"{$quantity}\" is not a quantity with at most four decimals.");
        }

        $units = ((int) $parts[2]) * self::SCALE + (int) str_pad($parts[3] ?? '0', 4, '0');

        return new self($parts[1] === '-' ? -$units : $units);
    }

    /**
     * This quantity × a unit price, rounded half away from zero to the cent.
     */
    public function times(Money $unitPrice): Money
    {
        return $unitPrice->scaled($this->units, self::SCALE);
    }

    public function isZero(): bool
    {
        return $this->units === 0;
    }

    public function isNegative(): bool
    {
        return $this->units < 0;
    }

    /**
     * "1.5000", or with fewer decimals when asked (never rounding away a
     * significant digit: "1.5" stays "1.50" with two decimals).
     */
    public function toDecimalString(int $decimals = 4): string
    {
        if ($decimals < 0 || $decimals > 4) {
            throw new InvalidArgumentException('Quantities carry between 0 and 4 decimals.');
        }

        $sign = $this->units < 0 ? '-' : '';
        $units = abs($this->units);
        $fraction = str_pad((string) ($units % self::SCALE), 4, '0', STR_PAD_LEFT);
        $kept = substr($fraction, 0, $decimals);

        if (rtrim(substr($fraction, $decimals), '0') !== '') {
            throw new InvalidArgumentException("{$sign}".intdiv($units, self::SCALE).".{$fraction} does not fit in {$decimals} decimals.");
        }

        return $sign.intdiv($units, self::SCALE).($decimals > 0 ? '.'.$kept : '');
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }
}
