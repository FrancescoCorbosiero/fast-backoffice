<?php

declare(strict_types=1);

namespace App\Modules\Cash\Models;

use App\Kernel\Dates\Frequency;
use App\Kernel\Dates\Recurrence;
use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Cash\Data\RecurringExpenseData;
use App\Modules\Cash\Database\Factories\RecurringExpenseFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing cost (hosting, software, rent): a schedule and the next
 * date it's due. "Log occurrence" records the movement and moves the date.
 *
 * @property int $id
 * @property string $name
 * @property int $amount_cents
 * @property string $currency
 * @property Frequency $frequency
 * @property CarbonImmutable $started_on
 * @property CarbonImmutable $next_due_on
 * @property ?int $account_id
 * @property ?int $category_id
 * @property ?int $contact_id
 * @property bool $is_active
 * @property ?string $notes
 * @property-read ?Account $account
 */
class RecurringExpense extends Model
{
    /** @use HasFactory<RecurringExpenseFactory> */
    use HasFactory;

    protected $table = 'cash_recurring_expenses';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'frequency' => Frequency::class,
            'started_on' => 'immutable_date',
            'next_due_on' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): RecurringExpenseFactory
    {
        return RecurringExpenseFactory::new();
    }

    /**
     * @param  Builder<RecurringExpense>  $query
     * @return Builder<RecurringExpense>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function amount(): Money
    {
        return new Money($this->amount_cents, $this->currency);
    }

    /**
     * The step rule: the schedule anchored on the start date (design.md §5.5).
     */
    public function recurrence(): Recurrence
    {
        return new Recurrence($this->frequency, $this->started_on);
    }

    public function dueDateAfter(CarbonImmutable $date): CarbonImmutable
    {
        return $this->recurrence()->nextAfter($date);
    }

    /**
     * The allocation of the movement logging one occurrence, so each period
     * is logged once.
     */
    public function allocationFor(CarbonImmutable $dueOn): Reference
    {
        return Reference::to('recurring_expense', $this->id.':'.$dueOn->toDateString());
    }

    public function toData(): RecurringExpenseData
    {
        return new RecurringExpenseData(
            id: $this->id,
            name: $this->name,
            amount: $this->amount(),
            frequency: $this->frequency,
            startedOn: $this->started_on,
            nextDueOn: $this->next_due_on,
            accountId: $this->account_id,
            categoryId: $this->category_id,
            contactId: $this->contact_id,
            isActive: $this->is_active,
        );
    }
}
