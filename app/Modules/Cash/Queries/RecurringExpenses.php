<?php

declare(strict_types=1);

namespace App\Modules\Cash\Queries;

use App\Modules\Cash\Data\RecurringExpenseData;
use App\Modules\Cash\Models\RecurringExpense;
use Carbon\CarbonImmutable;

final readonly class RecurringExpenses
{
    /**
     * @return list<RecurringExpenseData> by due date
     */
    public function active(): array
    {
        return array_values(RecurringExpense::query()->active()->orderBy('next_due_on')->orderBy('name')->get()
            ->map(fn (RecurringExpense $expense): RecurringExpenseData => $expense->toData())->all());
    }

    /**
     * Active expenses due on or before $date: what's waiting to be logged.
     *
     * @return list<RecurringExpenseData>
     */
    public function due(CarbonImmutable $date): array
    {
        return array_values(RecurringExpense::query()->active()->where('next_due_on', '<=', $date->toDateString())->orderBy('next_due_on')->get()
            ->map(fn (RecurringExpense $expense): RecurringExpenseData => $expense->toData())->all());
    }

    public function find(int $id): ?RecurringExpenseData
    {
        return RecurringExpense::query()->find($id)?->toData();
    }
}
