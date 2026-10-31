<?php

declare(strict_types=1);

use App\Kernel\Money\Money;
use App\Kernel\Money\Rounding;

it('is integer cents in a currency', function (): void {
    $money = new Money(12_345);

    expect($money->cents)->toBe(12_345)
        ->and($money->currency)->toBe('EUR')
        ->and($money->toDecimalString())->toBe('123.45')
        ->and((string) $money)->toBe('123.45');
});

it('refuses a currency that is not an ISO code', function (string $currency): void {
    expect(fn () => new Money(1, $currency))->toThrow(InvalidArgumentException::class);
})->with(['eur', 'EURO', '', '€']);

it('parses decimal strings exactly, with a dot or a comma', function (string $input, int $cents): void {
    expect(Money::fromDecimal($input)->cents)->toBe($cents);
})->with([
    ['1234.56', 123_456],
    ['1234,56', 123_456],
    ['0.5', 50],
    ['7', 700],
    ['-12.30', -1_230],
    [' 1.1 ', 110],
]);

it('refuses amounts with more than two decimals or that are not numbers', function (string $input): void {
    expect(fn () => Money::fromDecimal($input))->toThrow(InvalidArgumentException::class);
})->with(['1.234', 'abc', '1,2,3', '', '1e3']);

it('adds and subtracts in the same currency only', function (): void {
    $a = new Money(1_000);
    $b = new Money(250);

    expect($a->plus($b)->cents)->toBe(1_250)
        ->and($a->minus($b)->cents)->toBe(750)
        ->and(fn () => $a->plus(new Money(1, 'USD')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $a->compareTo(new Money(1, 'USD')))->toThrow(InvalidArgumentException::class);
});

it('scales by a ratio rounding half away from zero', function (int $cents, int $numerator, int $denominator, int $expected): void {
    expect((new Money($cents))->scaled($numerator, $denominator)->cents)->toBe($expected);
})->with([
    'exact' => [10_000, 22, 100, 2_200],
    'half up' => [5, 1, 2, 3],
    'half away from zero, negative' => [-5, 1, 2, -3],
    'below half' => [4, 1, 3, 1],
    'above half' => [5, 2, 3, 3],
    'negative below half' => [-4, 1, 3, -1],
]);

it('refuses a non-positive divisor', function (): void {
    expect(fn () => Rounding::divide(1, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Rounding::divide(1, -2))->toThrow(InvalidArgumentException::class);
});

it('compares, negates and sums', function (): void {
    $amounts = [new Money(100), new Money(-30), new Money(5)];

    expect(Money::sum($amounts)->cents)->toBe(75)
        ->and(Money::sum([])->isZero())->toBeTrue()
        ->and((new Money(100))->greaterThan(new Money(99)))->toBeTrue()
        ->and((new Money(100))->lessThan(new Money(99)))->toBeFalse()
        ->and((new Money(100))->equals(new Money(100)))->toBeTrue()
        ->and((new Money(100))->equals(new Money(100, 'USD')))->toBeFalse()
        ->and((new Money(-7))->negated()->cents)->toBe(7)
        ->and((new Money(-7))->abs()->cents)->toBe(7)
        ->and((new Money(-7))->isNegative())->toBeTrue()
        ->and((new Money(7))->isPositive())->toBeTrue()
        ->and((new Money(7))->multipliedBy(3)->cents)->toBe(21);
});

it('renders negative amounts with the sign in front', function (): void {
    expect((new Money(-5))->toDecimalString())->toBe('-0.05')
        ->and((new Money(-123_400))->toDecimalString())->toBe('-1234.00');
});

it('formats for Italian readers', function (): void {
    expect((new Money(123_456))->format())->toContain('1.234,56')->toContain('€');
});

it('reads amounts the way people type them, thousands separator included', function (): void {
    expect(Money::fromDecimal('1.500,00')->cents)->toBe(150_000)
        ->and(Money::fromDecimal('1,500.00')->cents)->toBe(150_000)
        ->and(Money::fromDecimal('1500,5')->cents)->toBe(150_050)
        ->and(Money::fromDecimal('12.34')->cents)->toBe(1_234)
        ->and(Money::fromDecimal('12,34')->cents)->toBe(1_234)
        ->and(fn () => Money::fromDecimal('1.2.3'))->toThrow(InvalidArgumentException::class);
});
