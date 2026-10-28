<?php

declare(strict_types=1);

use App\Kernel\Money\Money;
use App\Modules\Cash\Contracts\CashFlowSchedule;
use App\Modules\Cash\Data\ExpectedFlow;
use App\Modules\Cash\Data\Projection;
use App\Modules\Cash\Models\RecurringExpense;
use App\Modules\Cash\Queries\CashFlowProjection;
use Carbon\CarbonImmutable;

/**
 * The specification ported from hive-crm's CashFlowProjectionTest: the
 * projection is the sum of every schedule, overdue items count in the
 * first month, month-end schedules land on short months' last day.
 */
function projectFrom(string $today, int $months = 12): Projection
{
    return app(CashFlowProjection::class)->project(CarbonImmutable::parse($today), $months);
}

/**
 * @return list<array{string, string, int}>
 */
function flowsOf(Projection $projection): array
{
    return array_map(fn (ExpectedFlow $flow): array => [$flow->date->toDateString(), $flow->direction->value, $flow->amount->cents], $projection->flows);
}

/**
 * @param  list<ExpectedFlow>  $flows
 */
function contributeFlows(array $flows): void
{
    app()->instance('tests.cash-flow-schedule', new class($flows) implements CashFlowSchedule
    {
        public int $calls = 0;

        /** @param list<ExpectedFlow> $flows */
        public function __construct(private readonly array $flows) {}

        public function pending(CarbonImmutable $until): iterable
        {
            $this->calls++;

            return $this->flows;
        }
    });
    app()->tag(['tests.cash-flow-schedule'], CashFlowSchedule::TAG);
}

it('projects recurring expenses as money out, on their cycle', function (): void {
    RecurringExpense::factory()->create(['next_due_on' => '2026-05-20', 'amount_cents' => 5_000]);

    expect(flowsOf(projectFrom('2026-05-01', 3)))->toBe([
        ['2026-05-20', 'out', 5_000],
        ['2026-06-20', 'out', 5_000],
        ['2026-07-20', 'out', 5_000],
    ]);
});

it('skips inactive schedules and expenses without an amount', function (): void {
    RecurringExpense::factory()->create(['next_due_on' => '2026-06-01', 'is_active' => false]);
    RecurringExpense::factory()->create(['next_due_on' => '2026-06-01', 'amount_cents' => 0]);

    expect(projectFrom('2026-05-01')->isEmpty())->toBeTrue();
});

it('sums every registered schedule into a net per month with the right sign', function (): void {
    RecurringExpense::factory()->create(['name' => 'Hosting', 'next_due_on' => '2026-07-15', 'amount_cents' => 50_000]);
    contributeFlows([
        ExpectedFlow::in(CarbonImmutable::parse('2026-06-01'), new Money(10_000), 'Canone sito', 'recurring_invoice'),
        ExpectedFlow::in(CarbonImmutable::parse('2026-07-01'), new Money(10_000), 'Canone sito', 'recurring_invoice'),
        ExpectedFlow::in(CarbonImmutable::parse('2027-06-01'), new Money(99_999), 'Oltre la finestra', 'recurring_invoice'),
    ]);

    $projection = projectFrom('2026-05-01', 3);

    expect(flowsOf($projection))->toBe([
        ['2026-06-01', 'in', 10_000],
        ['2026-07-01', 'in', 10_000],
        ['2026-07-15', 'out', 50_000],
    ])
        ->and($projection->net()->cents)->toBe(20_000 - 50_000)
        ->and($projection->month('2026-07')?->net()->cents)->toBe(-40_000)
        ->and($projection->month('2026-05')?->net()->cents)->toBe(0)
        ->and($projection->month('2030-01'))->toBeNull()
        ->and(count($projection->months))->toBe(3);
});

it('carries everything still pending from before the window into its first month, flagged overdue', function (): void {
    RecurringExpense::factory()->create(['name' => 'Figma', 'next_due_on' => '2026-03-10', 'amount_cents' => 10_000]);
    contributeFlows([ExpectedFlow::in(CarbonImmutable::parse('2026-04-20'), new Money(5_000), 'Fattura 3/2026', 'receivable')]);

    $projection = projectFrom('2026-05-15', 3);

    expect(flowsOf($projection))->toBe([
        ['2026-03-10', 'out', 10_000],
        ['2026-04-10', 'out', 10_000],
        ['2026-04-20', 'in', 5_000],
        ['2026-05-10', 'out', 10_000],
        ['2026-06-10', 'out', 10_000],
        ['2026-07-10', 'out', 10_000],
    ])
        ->and($projection->month('2026-05')?->out->cents)->toBe(30_000)
        ->and($projection->month('2026-05')?->in->cents)->toBe(5_000)
        ->and($projection->month('2026-06')?->out->cents)->toBe(10_000)
        ->and(array_map($projection->isOverdue(...), $projection->flows))->toBe([true, true, true, true, false, false]);
});

it('lands month-end schedules on the last day of short months and comes back to the 31st', function (): void {
    RecurringExpense::factory()->create(['started_on' => '2026-01-31', 'next_due_on' => '2026-01-31']);

    expect(array_column(flowsOf(projectFrom('2026-01-01', 4)), 0))->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
});

it('shows the projection page and computes it once per render', function (): void {
    signInAsOwner();
    $this->travelTo('2026-05-15');
    RecurringExpense::factory()->create(['name' => 'Figma', 'next_due_on' => '2026-05-10']);
    contributeFlows([]);

    $this->get('/admin/cash/projection')
        ->assertOk()
        ->assertSee('Figma')
        ->assertSee('in ritardo')
        ->assertSee('spesa ricorrente')
        ->assertSee('mag 2026');

    expect(app('tests.cash-flow-schedule')->calls)->toBe(1);
});
