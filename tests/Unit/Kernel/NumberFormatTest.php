<?php

declare(strict_types=1);

use App\Kernel\Numbering\NumberFormat;

it('renders the one format used everywhere', function (string $pattern, string $expected): void {
    expect((new NumberFormat($pattern))->render(12, 2026))->toBe($expected);
})->with([
    ['{number}/{year}', '12/2026'],
    ['{number:4}/{year}', '0012/2026'],
    ['{year}-{number:3}', '2026-012'],
    ['FT{yy}{number:5}', 'FT2600012'],
    ['{number}', '12'],
]);

it('refuses patterns without a number or with unknown placeholders', function (string $pattern): void {
    expect(fn () => new NumberFormat($pattern))->toThrow(InvalidArgumentException::class);
})->with(['{year}', 'plain', '{number}/{yearx}', '{number}{']);

it('refuses numbers below 1', function (): void {
    expect(fn () => (new NumberFormat('{number}'))->render(0, 2026))->toThrow(InvalidArgumentException::class);
});
