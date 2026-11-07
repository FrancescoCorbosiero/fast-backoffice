<?php

declare(strict_types=1);

namespace App\Modules\Cash\Contributions;

use App\Kernel\Reference;
use App\Modules\Cash\Contracts\AllocationPresenter;
use App\Modules\Cash\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Modules\Cash\Models\RecurringExpense;
use Carbon\CarbonImmutable;

/**
 * recurring_expense:{expense}:{due date} on the Cash screens: the payment
 * of one occurrence leads back to the schedule it belongs to.
 */
final class RecurringExpenseAllocationPresenter implements AllocationPresenter
{
    public function supports(Reference $allocation): bool
    {
        return $allocation->type === 'recurring_expense';
    }

    public function label(Reference $allocation): string
    {
        [$id, $date] = self::parts($allocation);
        $expense = RecurringExpense::query()->find($id);
        $month = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::parse($date)->isoFormat('MMM YYYY') : $date;

        return 'Spesa ricorrente '.($expense !== null ? $expense->name : '#'.$id).($month !== null ? ' · '.$month : '');
    }

    public function url(Reference $allocation): ?string
    {
        [$id] = self::parts($allocation);

        return $id > 0 ? RecurringExpenseResource::getUrl('edit', ['record' => $id]) : null;
    }

    /**
     * @return array{int, ?string}
     */
    public static function parts(Reference $allocation): array
    {
        $segments = explode(':', $allocation->key, 2);

        return [(int) $segments[0], $segments[1] ?? null];
    }
}
