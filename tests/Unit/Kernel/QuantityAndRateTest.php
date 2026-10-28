<?php

declare(strict_types=1);

use App\Kernel\Money\Money;
use App\Kernel\Money\Quantity;
use App\Kernel\Money\VatRate;

it('keeps four decimals as an integer', function (string $input, int $units): void {
    expect(Quantity::fromDecimal($input)->units)->toBe($units);
})->with([
    ['1', 10_000],
    ['1.5', 15_000],
    ['0,25', 2_500],
    ['-1.1234', -11_234],
    ['2.0001', 20_001],
]);

it('refuses more than four decimals', function (): void {
    expect(fn () => Quantity::fromDecimal('1.12345'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Quantity::fromDecimal('x'))->toThrow(InvalidArgumentException::class);
});

it('multiplies a unit price rounding half away from zero without float drift', function (): void {
    // 1.15 × 0.10 = 0.115 → 0.12 (a naive float product is 0.11499…)
    expect(Quantity::fromDecimal('1.15')->times(new Money(10))->cents)->toBe(12)
        // 1.5 × 3.33 = 4.995 → 5.00
        ->and(Quantity::fromDecimal('1.5')->times(new Money(333))->cents)->toBe(500)
        // discount lines round symmetrically: -1.5 × 3.33 = -4.995 → -5.00
        ->and(Quantity::fromDecimal('-1.5')->times(new Money(333))->cents)->toBe(-500)
        ->and(Quantity::of(3)->times(new Money(1_000))->cents)->toBe(3_000);
});

it('renders with the decimals asked for, refusing to lose a digit', function (): void {
    expect(Quantity::fromDecimal('1.5')->toDecimalString())->toBe('1.5000')
        ->and(Quantity::fromDecimal('1.5')->toDecimalString(2))->toBe('1.50')
        ->and(Quantity::of(2)->toDecimalString(0))->toBe('2')
        ->and(Quantity::fromDecimal('-0.25')->toDecimalString(2))->toBe('-0.25')
        ->and(fn () => Quantity::fromDecimal('1.2345')->toDecimalString(2))->toThrow(InvalidArgumentException::class);
});

it('holds VAT rates in basis points', function (): void {
    expect(VatRate::percent(22)->basisPoints)->toBe(2_200)
        ->and(VatRate::fromPercentString('4,5')->basisPoints)->toBe(450)
        ->and(VatRate::fromPercentString('22.00')->toPercentString())->toBe('22.00')
        ->and(VatRate::zero()->isZero())->toBeTrue()
        ->and((string) VatRate::percent(10))->toBe('10.00')
        ->and(fn () => new VatRate(10_001))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new VatRate(-1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => VatRate::fromPercentString('22.001'))->toThrow(InvalidArgumentException::class);
});

it('computes VAT on an amount and back-derives the net from a gross', function (): void {
    expect(VatRate::percent(22)->on(new Money(10_000))->cents)->toBe(2_200)
        ->and(VatRate::percent(22)->on(new Money(100))->cents)->toBe(22)
        ->and(VatRate::percent(22)->netFromGross(new Money(12_200))->cents)->toBe(10_000)
        ->and(VatRate::zero()->netFromGross(new Money(10_000))->cents)->toBe(10_000)
        // 100.00 / 1.22 = 81.9672… → 81.97; 81.97 + 22% (18.03) = 100.00
        ->and(VatRate::percent(22)->netFromGross(new Money(10_000))->cents)->toBe(8_197)
        ->and(VatRate::percent(22)->on(new Money(8_197))->plus(new Money(8_197))->cents)->toBe(10_000);
});
