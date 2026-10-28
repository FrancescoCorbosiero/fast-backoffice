<?php

declare(strict_types=1);

use App\Kernel\Dates\Frequency;
use App\Kernel\Dates\Months;
use App\Kernel\Dates\Occurrences;
use App\Kernel\Dates\Recurrence;
use Carbon\CarbonImmutable;

function day(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

it('adds months without overflowing into the next month', function (string $from, int $months, string $expected): void {
    expect(Months::add(day($from), $months)->toDateString())->toBe($expected);
})->with([
    ['2026-01-31', 1, '2026-02-28'],
    ['2028-01-31', 1, '2028-02-29'],
    ['2028-02-29', 12, '2029-02-28'],
    ['2026-03-31', -1, '2026-02-28'],
    ['2026-01-15', 2, '2026-03-15'],
    ['2026-10-31', 4, '2027-02-28'],
]);

it('subtracts months the same way', function (): void {
    expect(Months::subtract(day('2026-05-31'), 1)->toDateString())->toBe('2026-04-30');
});

it('anchors a day in a month, clamping to the month length', function (): void {
    expect(Months::anchored(day('2026-04-10'), 31)->toDateString())->toBe('2026-04-30')
        ->and(Months::anchored(day('2026-02-01'), 29)->toDateString())->toBe('2026-02-28')
        ->and(Months::anchored(day('2028-02-01'), 29)->toDateString())->toBe('2028-02-29')
        ->and(Months::anchored(day('2026-05-20'), 1)->toDateString())->toBe('2026-05-01')
        ->and(fn () => Months::anchored(day('2026-05-20'), 32))->toThrow(InvalidArgumentException::class);
});

it('counts whole calendar months between dates', function (): void {
    expect(Months::between(day('2026-01-31'), day('2026-02-01')))->toBe(1)
        ->and(Months::between(day('2026-01-01'), day('2027-01-01')))->toBe(12)
        ->and(Months::between(day('2026-06-01'), day('2026-03-01')))->toBe(-3);
});

it('never drifts: a schedule anchored on the 31st comes back to the 31st', function (): void {
    $schedule = new Recurrence(Frequency::Monthly, day('2026-01-31'));

    $dates = array_map(fn (int $i): string => $schedule->occurrence($i)->toDateString(), range(0, 4));

    expect($dates)->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'])
        ->and($schedule->anchorDay())->toBe(31);
});

it('steps by the frequency', function (Frequency $frequency, string $expected): void {
    expect((new Recurrence($frequency, day('2026-01-31')))->occurrence(1)->toDateString())->toBe($expected);
})->with([
    [Frequency::Monthly, '2026-02-28'],
    [Frequency::Bimonthly, '2026-03-31'],
    [Frequency::Quarterly, '2026-04-30'],
    [Frequency::Semiannual, '2026-07-31'],
    [Frequency::Yearly, '2027-01-31'],
]);

it('finds the next occurrence after a date', function (string $after, string $expected): void {
    $schedule = new Recurrence(Frequency::Quarterly, day('2026-01-31'));

    expect($schedule->nextAfter(day($after))->toDateString())->toBe($expected);
})->with([
    'before the start' => ['2025-06-01', '2026-01-31'],
    'on the start' => ['2026-01-31', '2026-04-30'],
    'between' => ['2026-02-15', '2026-04-30'],
    'on an occurrence' => ['2026-04-30', '2026-07-31'],
    'years later' => ['2030-12-31', '2031-01-31'],
    'with a time of day' => ['2026-04-30 15:00', '2026-07-31'],
]);

it('finds the next occurrence on or after a date', function (): void {
    $schedule = new Recurrence(Frequency::Monthly, day('2026-01-31'));

    expect($schedule->nextOnOrAfter(day('2026-02-28'))->toDateString())->toBe('2026-02-28')
        ->and($schedule->nextOnOrAfter(day('2026-03-01'))->toDateString())->toBe('2026-03-31');
});

it('lists the occurrences in a window, both ends inclusive', function (): void {
    $schedule = new Recurrence(Frequency::Bimonthly, day('2026-01-10'));

    $dates = array_map(fn (CarbonImmutable $date): string => $date->toDateString(), iterator_to_array($schedule->between(day('2026-01-10'), day('2026-07-10')), false));

    expect($dates)->toBe(['2026-01-10', '2026-03-10', '2026-05-10', '2026-07-10']);
});

it('refuses a negative occurrence index', function (): void {
    expect(fn () => (new Recurrence(Frequency::Monthly, day('2026-01-10')))->occurrence(-1))->toThrow(InvalidArgumentException::class);
});

it('walks occurrences from the first pending date through the end of the window', function (): void {
    $dates = Occurrences::until(day('2026-01-10'), fn (CarbonImmutable $date): CarbonImmutable => Months::add($date, 2), day('2026-07-10'));

    expect(array_map(fn (CarbonImmutable $date): string => $date->toDateString(), iterator_to_array($dates, false)))
        ->toBe(['2026-01-10', '2026-03-10', '2026-05-10', '2026-07-10']);
});

it('refuses a schedule step that does not move forward', function (): void {
    $dates = Occurrences::until(day('2026-01-10'), fn (CarbonImmutable $date): CarbonImmutable => $date, day('2026-12-31'));

    expect(fn () => iterator_to_array($dates, false))->toThrow(LogicException::class);
});

it('labels frequencies in Italian', function (): void {
    expect(Frequency::Monthly->label())->toBe('Mensile')
        ->and(Frequency::Yearly->months())->toBe(12);
});
