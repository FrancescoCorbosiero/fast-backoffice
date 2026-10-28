<?php

declare(strict_types=1);

namespace App\Kernel\Money;

use InvalidArgumentException;
use Stringable;

/**
 * A VAT rate in basis points (22% → 2 200), so rates with two decimals
 * (4.5%) stay integers.
 */
final readonly class VatRate implements Stringable
{
    public const int BASIS_POINTS_PER_PERCENT = 100;

    public const int BASIS_POINTS_PER_UNIT = 10_000;

    public function __construct(public int $basisPoints)
    {
        if ($basisPoints < 0 || $basisPoints > self::BASIS_POINTS_PER_UNIT) {
            throw new InvalidArgumentException("A VAT rate is between 0% and 100%, got {$basisPoints} basis points.");
        }
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function percent(int $percent): self
    {
        return new self($percent * self::BASIS_POINTS_PER_PERCENT);
    }

    /**
     * Parse "22", "22.00", "4,5" or "0".
     */
    public static function fromPercentString(string $percent): self
    {
        $normalised = str_replace(',', '.', trim($percent));

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalised, $parts) !== 1) {
            throw new InvalidArgumentException("\"{$percent}\" is not a percentage with at most two decimals.");
        }

        return new self(((int) $parts[1]) * self::BASIS_POINTS_PER_PERCENT + (int) str_pad($parts[2] ?? '0', 2, '0'));
    }

    /**
     * VAT on a taxable amount, rounded half away from zero.
     */
    public function on(Money $taxable): Money
    {
        return $taxable->scaled($this->basisPoints, self::BASIS_POINTS_PER_UNIT);
    }

    /**
     * The net amount whose gross (net plus VAT at this rate) is closest to
     * the given gross amount.
     */
    public function netFromGross(Money $gross): Money
    {
        return $gross->scaled(self::BASIS_POINTS_PER_UNIT, self::BASIS_POINTS_PER_UNIT + $this->basisPoints);
    }

    public function isZero(): bool
    {
        return $this->basisPoints === 0;
    }

    public function equals(VatRate $other): bool
    {
        return $this->basisPoints === $other->basisPoints;
    }

    /**
     * "22.00": the form FatturaPA's AliquotaIVA expects.
     */
    public function toPercentString(): string
    {
        return sprintf('%d.%02d', intdiv($this->basisPoints, self::BASIS_POINTS_PER_PERCENT), $this->basisPoints % self::BASIS_POINTS_PER_PERCENT);
    }

    public function __toString(): string
    {
        return $this->toPercentString();
    }
}
