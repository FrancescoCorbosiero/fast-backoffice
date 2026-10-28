<?php

declare(strict_types=1);

use App\Kernel\Numbering\NumberSequence;
use Illuminate\Support\Facades\DB;

function sequence(): NumberSequence
{
    return app(NumberSequence::class);
}

it('numbers gaplessly from 1, per series and year', function (): void {
    $numbers = DB::transaction(fn (): array => [
        sequence()->next('invoices', 2026),
        sequence()->next('invoices', 2026),
        sequence()->next('invoices', 2027),
        sequence()->next('quotes', 2026),
        sequence()->next('invoices', 2026),
    ]);

    expect($numbers)->toBe([1, 2, 1, 1, 3])
        ->and(sequence()->last('invoices', 2026))->toBe(3)
        ->and(sequence()->last('invoices', 2030))->toBeNull();
});

it('releases the number when the transaction rolls back, so there is no gap', function (): void {
    DB::transaction(fn (): int => sequence()->next('invoices', 2026));

    try {
        DB::transaction(function (): void {
            sequence()->next('invoices', 2026);
            throw new RuntimeException('issue failed after the number was taken');
        });
    } catch (RuntimeException) {
    }

    expect(DB::transaction(fn (): int => sequence()->next('invoices', 2026)))->toBe(2);
});

it('starts a year from its opening number, once', function (): void {
    expect(DB::transaction(fn (): int => sequence()->next('invoices', 2026, openingNumber: 41)))->toBe(41)
        ->and(DB::transaction(fn (): int => sequence()->next('invoices', 2026, openingNumber: 99)))->toBe(42);
});

it('refuses to allocate outside a transaction', function (): void {
    // The test itself runs inside a transaction: use a connection that doesn't.
    $outside = new NumberSequence(DB::connection('pgsql_other'));

    expect(fn () => $outside->next('invoices', 2026))->toThrow(LogicException::class);
});

it('refuses bad series codes and opening numbers', function (): void {
    expect(fn () => DB::transaction(fn (): int => sequence()->next('Invoices', 2026)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DB::transaction(fn (): int => sequence()->next('invoices', 2026, 0)))->toThrow(InvalidArgumentException::class);
});

it('locks the row so concurrent issuers queue up rather than share a number', function (): void {
    // A second connection blocks on the locked row until the first commits;
    // with a short lock timeout it fails instead, which proves the lock.
    $other = DB::connection('pgsql_other');

    DB::beginTransaction();
    sequence()->next('invoices', 2026);

    $other->statement("SET lock_timeout = '200ms'");
    $other->beginTransaction();

    try {
        expect(fn () => (new NumberSequence($other))->next('invoices', 2026))->toThrow(Illuminate\Database\QueryException::class);
    } finally {
        $other->rollBack();
        DB::rollBack();
    }
})->skip(fn (): bool => config('database.connections.pgsql_other') === null, 'needs a second PostgreSQL connection');
