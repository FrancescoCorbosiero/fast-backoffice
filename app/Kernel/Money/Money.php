<?php

declare(strict_types=1);

namespace App\Kernel\Money;

use InvalidArgumentException;
use NumberFormatter;
use Stringable;

/**
 * An amount of money: integer minor units (cents) and an ISO 4217
 * currency. Arithmetic only between equal currencies. Never a float.
 */
final readonly class Money implements Stringable
{
    public function __construct(
        public int $cents,
        public string $currency = 'EUR',
    ) {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Currency must be a 3-letter ISO 4217 code, got \"{$currency}\".");
        }
    }

    public static function zero(string $currency = 'EUR'): self
    {
        return new self(0, $currency);
    }

    public static function fromCents(int $cents, string $currency = 'EUR'): self
    {
        return new self($cents, $currency);
    }

    /**
     * Parse a decimal amount such as "1234.56", "1234,56", "-12" or "0.5".
     * Strings only: a float literal would already have lost precision.
     */
    public static function fromDecimal(string $amount, string $currency = 'EUR'): self
    {
        $normalised = str_replace(',', '.', trim($amount));

        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $normalised, $parts) !== 1) {
            throw new InvalidArgumentException("\"{$amount}\" is not a decimal amount with at most two decimals.");
        }

        $cents = ((int) $parts[2]) * 100 + (int) str_pad($parts[3] ?? '0', 2, '0');

        return new self($parts[1] === '-' ? -$cents : $cents, $currency);
    }

    /**
     * @param  iterable<Money>  $amounts
     */
    public static function sum(iterable $amounts, string $currency = 'EUR'): self
    {
        $total = self::zero($currency);
        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    public function plus(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function minus(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    public function multipliedBy(int $factor): self
    {
        return new self($this->cents * $factor, $this->currency);
    }

    /**
     * This amount × numerator ÷ denominator, rounded half away from zero.
     * The one way to apply a rate or a ratio to money.
     */
    public function scaled(int $numerator, int $denominator): self
    {
        return new self(Rounding::divide($this->cents * $numerator, $denominator), $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->cents, $this->currency);
    }

    public function abs(): self
    {
        return new self(abs($this->cents), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function equals(Money $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }

    /**
     * -1, 0 or 1, like the spaceship operator.
     */
    public function compareTo(Money $other): int
    {
        $this->assertSameCurrency($other);

        return $this->cents <=> $other->cents;
    }

    public function greaterThan(Money $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function lessThan(Money $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * "1234.56": the form FatturaPA and the database expect.
     */
    public function toDecimalString(): string
    {
        $sign = $this->cents < 0 ? '-' : '';
        $cents = abs($this->cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    /**
     * Localised for display, e.g. "1.234,56 €" in Italian.
     */
    public function format(string $locale = 'it_IT'): string
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $formatted = $formatter->formatCurrency($this->cents / 100, $this->currency);

        return $formatted === false ? $this->toDecimalString().' '.$this->currency : $formatted;
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency} with {$other->currency}.");
        }
    }
}
