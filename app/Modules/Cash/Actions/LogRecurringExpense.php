<?php

declare(strict_types=1);

namespace App\Modules\Cash\Actions;

use App\Modules\Cash\Data\MovementData;
use App\Modules\Cash\Data\MovementInput;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Models\Movement;
use App\Modules\Cash\Models\RecurringExpense;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * "Log occurrence": records the movement for the occurrence that is due
 * and moves the due date to the next one. Idempotent per occurrence: the
 * movement is allocated to (expense, due date), and logging the same
 * occurrence again returns the movement already recorded.
 */
final readonly class LogRecurringExpense
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordMovement $recordMovement,
    ) {}

    /**
     * @param  ?CarbonImmutable  $paidOn  when the money left; the due date by default
     * @param  ?int  $accountId  the account it left from; the expense's own by default
     *
     * @throws ValidationException
     */
    public function log(int $expenseId, ?CarbonImmutable $paidOn = null, ?int $accountId = null): MovementData
    {
        return $this->db->transaction(function () use ($expenseId, $paidOn, $accountId): MovementData {
            $expense = RecurringExpense::query()->lockForUpdate()->findOrFail($expenseId);
            $dueOn = $expense->next_due_on;
            $allocation = $expense->allocationFor($dueOn);

            $existing = Movement::query()->where('allocation', $allocation->toString())->first();

            if ($existing !== null) {
                return $existing->toData();
            }

            $accountId ??= $expense->account_id;

            if ($accountId === null) {
                throw ValidationException::withMessages(['account_id' => 'Scegli il conto da cui è uscito il pagamento.']);
            }

            $movement = $this->recordMovement->record(new MovementInput(
                accountId: $accountId,
                direction: Direction::Out,
                amount: $expense->amount(),
                occurredOn: $paidOn ?? $dueOn,
                description: $expense->name.' ('.$dueOn->isoFormat('MMM YYYY').')',
                categoryId: $expense->category_id,
                contactId: $expense->contact_id,
                allocation: $allocation,
            ));

            $expense->forceFill(['next_due_on' => $expense->dueDateAfter($dueOn)->toDateString()])->save();

            return $movement;
        });
    }
}
