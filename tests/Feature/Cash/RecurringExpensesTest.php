<?php

declare(strict_types=1);

use App\Kernel\Dates\Frequency;
use App\Modules\Cash\Actions\LogRecurringExpense;
use App\Modules\Cash\Models\Account;
use App\Modules\Cash\Models\Movement;
use App\Modules\Cash\Models\RecurringExpense;
use App\Modules\Cash\Queries\RecurringExpenses;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

it('logs an occurrence: records the movement and moves the due date, once per period', function (): void {
    $account = Account::factory()->create();
    $expense = RecurringExpense::factory()->create(['account_id' => $account->id, 'started_on' => '2026-01-31', 'next_due_on' => '2026-01-31', 'amount_cents' => 5_000]);

    $first = app(LogRecurringExpense::class)->log($expense->id);
    $dueAfterFirst = $expense->refresh()->next_due_on->toDateString();
    $again = app(LogRecurringExpense::class)->log($expense->id, CarbonImmutable::parse('2026-02-10'));

    expect($first->amount->cents)->toBe(5_000)
        ->and($first->direction->value)->toBe('out')
        ->and($first->occurredOn->toDateString())->toBe('2026-01-31')
        ->and($first->allocation?->toString())->toBe("recurring_expense:{$expense->id}:2026-01-31")
        ->and($first->description)->toContain('Figma')
        ->and($dueAfterFirst)->toBe('2026-02-28')
        ->and($again->occurredOn->toDateString())->toBe('2026-02-10')
        ->and($again->allocation?->toString())->toBe("recurring_expense:{$expense->id}:2026-02-28")
        ->and($expense->refresh()->next_due_on->toDateString())->toBe('2026-03-31')
        ->and(Movement::query()->count())->toBe(2);
});

it('does not log the same occurrence twice', function (): void {
    $account = Account::factory()->create();
    $expense = RecurringExpense::factory()->create(['account_id' => $account->id]);
    Movement::factory()->for($account)->out(5_000, '2026-01-20')->allocatedTo("recurring_expense:{$expense->id}:2026-01-20")->create();

    $logged = app(LogRecurringExpense::class)->log($expense->id);

    expect(Movement::query()->count())->toBe(1)
        ->and($logged->occurredOn->toDateString())->toBe('2026-01-20');
});

it('needs an account to log against', function (): void {
    $expense = RecurringExpense::factory()->create(['account_id' => null]);

    expect(fn () => app(LogRecurringExpense::class)->log($expense->id))->toThrow(ValidationException::class);

    $account = Account::factory()->create();
    expect(app(LogRecurringExpense::class)->log($expense->id, accountId: $account->id)->accountId)->toBe($account->id);
});

it('steps every frequency without drifting from the anchor day', function (): void {
    $expense = RecurringExpense::factory()->create(['frequency' => Frequency::Quarterly, 'started_on' => '2026-01-31', 'next_due_on' => '2026-04-30']);

    expect($expense->dueDateAfter(CarbonImmutable::parse('2026-04-30'))->toDateString())->toBe('2026-07-31')
        ->and($expense->dueDateAfter(CarbonImmutable::parse('2026-07-31'))->toDateString())->toBe('2026-10-31');
});

it('lists active and due expenses', function (): void {
    RecurringExpense::factory()->create(['name' => 'Late', 'next_due_on' => '2026-03-01']);
    RecurringExpense::factory()->create(['name' => 'Soon', 'next_due_on' => '2026-03-20']);
    RecurringExpense::factory()->create(['name' => 'Off', 'next_due_on' => '2026-03-01', 'is_active' => false]);
    $query = app(RecurringExpenses::class);

    expect(array_map(fn ($expense): string => $expense->name, $query->active()))->toBe(['Late', 'Soon'])
        ->and(array_map(fn ($expense): string => $expense->name, $query->due(CarbonImmutable::parse('2026-03-10'))))->toBe(['Late'])
        ->and($query->active()[0]->isOverdue(CarbonImmutable::parse('2026-03-10')))->toBeTrue()
        ->and($query->active()[1]->isOverdue(CarbonImmutable::parse('2026-03-10')))->toBeFalse()
        ->and($query->find(999))->toBeNull();
});
