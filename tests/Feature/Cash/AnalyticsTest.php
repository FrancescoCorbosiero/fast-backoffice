<?php

declare(strict_types=1);

use App\Modules\Cash\Data\MonthTotals;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Models\Account;
use App\Modules\Cash\Models\Category;
use App\Modules\Cash\Models\Movement;
use App\Modules\Cash\Queries\Analytics;
use App\Modules\Cash\Queries\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function queries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();

    return count(DB::getQueryLog());
}

it('gives the monthly series with empty months, in one query however many movements', function (): void {
    $account = Account::factory()->create();
    Movement::factory()->for($account)->in(10_000, '2026-01-31')->create();
    Movement::factory()->for($account)->in(5_000, '2026-03-10')->create();
    Movement::factory()->for($account)->out(2_000, '2026-03-20')->create();
    Movement::factory()->for($account)->in(99_999, '2026-03-21')->create(['is_taxable' => false]);
    Movement::factory()->for($account)->in(1, '2025-12-31')->create();
    $analytics = app(Analytics::class);

    $series = $analytics->monthly(3, CarbonImmutable::parse('2026-03-15'));
    $rows = array_map(fn (MonthTotals $month): array => [$month->key(), $month->in->cents, $month->out->cents, $month->net()->cents], $series);

    expect($rows)->toBe([
        ['2026-01', 10_000, 0, 10_000],
        ['2026-02', 0, 0, 0],
        ['2026-03', 5_000, 2_000, 3_000],
    ])
        ->and($analytics->monthly(1, CarbonImmutable::parse('2026-03-15'), taxableOnly: false)[0]->in->cents)->toBe(104_999)
        ->and(queries(fn () => $analytics->monthly(12, CarbonImmutable::parse('2026-03-15'))))->toBe(1);

    Movement::factory()->for($account)->count(20)->create(['occurred_on' => '2026-02-10']);
    expect(queries(fn () => $analytics->monthly(12, CarbonImmutable::parse('2026-03-15'))))->toBe(1);
});

it('totals the year to date and a period', function (): void {
    $account = Account::factory()->create();
    Movement::factory()->for($account)->in(10_000, '2026-01-05')->create();
    Movement::factory()->for($account)->in(3_000, '2026-04-01')->create();
    Movement::factory()->for($account)->out(4_000, '2026-02-05')->create();
    Movement::factory()->for($account)->in(7_000, '2025-12-31')->create();
    $analytics = app(Analytics::class);

    expect($analytics->yearToDate(Direction::In, CarbonImmutable::parse('2026-03-31'))->cents)->toBe(10_000)
        ->and($analytics->yearToDate(Direction::In, CarbonImmutable::parse('2026-04-30'))->cents)->toBe(13_000)
        ->and($analytics->yearToDate(Direction::Out, CarbonImmutable::parse('2026-04-30'))->cents)->toBe(4_000)
        ->and($analytics->total(Direction::In, CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-12-31'))->cents)->toBe(7_000)
        ->and($analytics->yearToDate(Direction::In, CarbonImmutable::parse('2024-06-30'))->isZero())->toBeTrue();
});

it('breaks down a period by category, largest first, uncategorised together', function (): void {
    $account = Account::factory()->create();
    $hosting = Category::factory()->create(['name' => 'Hosting']);
    $software = Category::factory()->create(['name' => 'Software']);
    Movement::factory()->for($account)->out(1_500, '2026-04-05')->create(['category_id' => $hosting->id]);
    Movement::factory()->for($account)->out(20_000, '2026-04-20')->create(['category_id' => $software->id]);
    Movement::factory()->for($account)->out(500, '2026-04-25')->create();
    Movement::factory()->for($account)->out(99_999, '2026-05-01')->create(['category_id' => $software->id]);
    Movement::factory()->for($account)->in(8_000, '2026-04-10')->create();
    $analytics = app(Analytics::class);

    $breakdown = $analytics->byCategory(Direction::Out, CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-04-30'));

    expect(array_map(fn ($total): array => [$total->name, $total->total->cents, $total->movements], $breakdown))->toBe([
        ['Software', 20_000, 1],
        ['Hosting', 1_500, 1],
        ['(senza categoria)', 500, 1],
    ])
        ->and($breakdown[0]->categoryId)->toBe($software->id)
        ->and($breakdown[2]->categoryId)->toBeNull()
        ->and($analytics->byCategory(Direction::In, CarbonImmutable::parse('2030-01-01'), CarbonImmutable::parse('2030-12-31')))->toBe([])
        ->and(queries(fn () => $analytics->byCategory(Direction::Out, CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-04-30'))))->toBe(1);
});

it('produces an account statement with running balances in a fixed number of queries', function (): void {
    $account = Account::factory()->create(['opening_balance_cents' => 10_000, 'opened_on' => '2026-01-01']);
    $other = Account::factory()->create();
    $contact = App\Modules\Contacts\Models\Contact::factory()->create(['display_name' => 'Mario Rossi']);
    $category = Category::factory()->income()->create(['name' => 'Consulenza']);
    Movement::factory()->for($account)->in(5_000, '2026-02-20')->create();
    Movement::factory()->for($account)->in(3_000, '2026-03-01')->create(['description' => 'A', 'contact_id' => $contact->id, 'category_id' => $category->id]);
    Movement::factory()->for($account)->out(1_000, '2026-03-01')->create(['description' => 'B']);
    Movement::factory()->for($account)->out(500, '2026-03-20')->create(['description' => 'C']);
    Movement::factory()->for($other)->out(99_999, '2026-03-05')->create();
    Movement::factory()->for($account)->in(1, '2026-04-01')->create();
    $ledger = app(Ledger::class);

    $lines = $ledger->statement($account->id, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect(array_map(fn ($line): array => [$line->movement->description, $line->movement->signedAmount()->cents, $line->balanceAfter->cents], $lines))->toBe([
        ['A', 3_000, 18_000],
        ['B', -1_000, 17_000],
        ['C', -500, 16_500],
    ])
        ->and($lines[0]->contactName)->toBe('Mario Rossi')
        ->and($lines[0]->categoryName)->toBe('Consulenza')
        ->and($lines[1]->contactName)->toBeNull();

    $count = queries(fn () => $ledger->statement($account->id, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')));
    Movement::factory()->for($account)->count(30)->create(['occurred_on' => '2026-03-10', 'contact_id' => App\Modules\Contacts\Models\Contact::factory()->create()->id]);
    expect(queries(fn () => $ledger->statement($account->id, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'))))->toBe($count);
});
