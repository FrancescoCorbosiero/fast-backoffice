<?php

declare(strict_types=1);

namespace App\Modules\Cash\Contributions;

use App\Kernel\Dates\Occurrences;
use App\Modules\Cash\Contracts\CashFlowSchedule;
use App\Modules\Cash\Data\ExpectedFlow;
use App\Modules\Cash\Models\RecurringExpense;
use Carbon\CarbonImmutable;

/**
 * Money out: each occurrence of an active recurring expense from its next
 * due date, including one overdue that nobody has logged yet.
 */
final class RecurringExpensesSchedule implements CashFlowSchedule
{
    public function pending(CarbonImmutable $until): iterable
    {
        $expenses = RecurringExpense::query()->active()
            ->where('amount_cents', '>', 0)
            ->where('next_due_on', '<=', $until->toDateString())
            ->get();

        foreach ($expenses as $expense) {
            $amount = $expense->amount();

            foreach (Occurrences::until($expense->next_due_on, $expense->dueDateAfter(...), $until) as $date) {
                yield ExpectedFlow::out($date, $amount, $expense->name, 'recurring_expense', $expense->allocationFor($date));
            }
        }
    }
}
