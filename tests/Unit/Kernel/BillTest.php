<?php

declare(strict_types=1);

use App\Kernel\Money\Money;
use App\Kernel\Money\Quantity;
use App\Kernel\Money\VatRate;
use App\Kernel\Vat\Bill;
use App\Kernel\Vat\Line;
use App\Kernel\Vat\Natura;
use App\Kernel\Vat\VatGroup;

/**
 * The specification ported from hive-crm's tests/Unit/Shared/LineItemsTest.php.
 */
function line(string $description, string $qty, int $unitCents, int $ratePercent, ?Natura $natura = null): Line
{
    return new Line($description, Quantity::fromDecimal($qty), new Money($unitCents), VatRate::percent($ratePercent), $natura);
}

it('sums line nets and applies VAT per rate', function (): void {
    $bill = new Bill([
        line('A', '1', 10_000, 22),
        line('B', '2', 5_000, 10),
    ]);

    expect($bill->subtotal()->cents)->toBe(20_000)
        ->and($bill->vat()->cents)->toBe(3_200)
        ->and($bill->total()->cents)->toBe(23_200)
        ->and($bill->total()->currency)->toBe('EUR');
});

it('rounds VAT once per rate on the summed taxable amount, not once per line', function (): void {
    // Ten lines of €0.10 at 22%: per-line rounding would give 10 × 2 = 20
    // cents of VAT, but SdI checks Imposta against 22% of the €1.00 taxable
    // total (= 22 cents) in DatiRiepilogo.
    $bill = new Bill(array_fill(0, 10, line('x', '1', 10, 22)));

    expect($bill->subtotal()->cents)->toBe(100)
        ->and($bill->vat()->cents)->toBe(22);
});

it('groups the VAT summary by rate and natura, in order of first appearance', function (): void {
    $bill = new Bill([
        line('Taxable', '1', 1_000, 22),
        line('Exempt A', '1', 500, 0, Natura::N2_2),
        line('Exempt B', '1', 300, 0, Natura::N4),
        line('Taxable 2', '3', 1_000, 22),
    ]);

    $summary = array_map(
        fn (VatGroup $group): array => [$group->vatRate->basisPoints, $group->natura?->value, $group->taxable->cents, $group->vat->cents],
        $bill->summary(),
    );

    expect($summary)->toBe([
        [2_200, null, 4_000, 880],
        [0, 'N2.2', 500, 0],
        [0, 'N4', 300, 0],
    ]);
});

it('requires a natura on a 0% line and refuses one on a taxed line', function (): void {
    expect(fn () => line('no natura', '1', 100, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => line('taxed with natura', '1', 100, 22, Natura::N2_2))->toThrow(InvalidArgumentException::class);
});

it('computes each line net from the quantity and unit price', function (): void {
    expect(line('x', '1.15', 10, 0, Natura::N2_2)->net->cents)->toBe(12)
        ->and(line('discount', '-1.5', 333, 0, Natura::N2_2)->net->cents)->toBe(-500);
});

it('is empty-safe', function (): void {
    $bill = new Bill([]);

    expect($bill->isEmpty())->toBeTrue()
        ->and($bill->summary())->toBe([])
        ->and($bill->total()->cents)->toBe(0);
});

it('refuses lines in another currency', function (): void {
    $line = new Line('usd', Quantity::of(1), new Money(100, 'USD'), VatRate::percent(22));

    expect(fn () => new Bill([$line]))->toThrow(InvalidArgumentException::class);
});

it('nets a discount line against the same VAT group', function (): void {
    $bill = new Bill([
        line('Service', '1', 10_000, 22),
        line('Discount', '-1', 1_000, 22),
    ]);

    expect($bill->summary())->toHaveCount(1)
        ->and($bill->subtotal()->cents)->toBe(9_000)
        ->and($bill->vat()->cents)->toBe(1_980);
});

it('knows which nature codes owe stamp duty', function (Natura $natura, bool $expected): void {
    expect($natura->stampDutyApplies())->toBe($expected);
})->with([
    [Natura::N1, true],
    [Natura::N2_1, true],
    [Natura::N2_2, true],
    [Natura::N3_5, true],
    [Natura::N4, true],
    [Natura::N3_1, false],
    [Natura::N3_6, false],
    [Natura::N6_1, false],
    [Natura::N7, false],
]);
