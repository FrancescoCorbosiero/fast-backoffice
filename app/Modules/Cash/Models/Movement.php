<?php

declare(strict_types=1);

namespace App\Modules\Cash\Models;

use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Cash\Data\MovementData;
use App\Modules\Cash\Database\Factories\MovementFactory;
use App\Modules\Cash\Enums\Direction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The only record of money moving (design.md §5.4).
 *
 * @property int $id
 * @property int $account_id
 * @property Direction $direction
 * @property int $amount_cents
 * @property string $currency
 * @property CarbonImmutable $occurred_on
 * @property string $description
 * @property ?int $category_id
 * @property ?int $contact_id
 * @property ?string $allocation
 * @property bool $is_taxable
 * @property ?string $notes
 * @property-read Account $account
 * @property-read ?Category $category
 */
class Movement extends Model
{
    /** @use HasFactory<MovementFactory> */
    use HasFactory;

    protected $table = 'cash_movements';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            'amount_cents' => 'integer',
            'occurred_on' => 'immutable_date',
            'is_taxable' => 'boolean',
        ];
    }

    protected static function newFactory(): MovementFactory
    {
        return MovementFactory::new();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @param  Builder<Movement>  $query
     * @return Builder<Movement>
     */
    public function scopeBetween(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()]);
    }

    public function amount(): Money
    {
        return new Money($this->amount_cents, $this->currency);
    }

    /**
     * The amount with its sign: positive in, negative out.
     */
    public function signedAmount(): Money
    {
        return $this->amount()->multipliedBy($this->direction->sign());
    }

    public function isAllocated(): bool
    {
        return $this->allocation !== null;
    }

    public function toData(): MovementData
    {
        return new MovementData(
            id: $this->id,
            accountId: $this->account_id,
            direction: $this->direction,
            amount: $this->amount(),
            occurredOn: $this->occurred_on,
            description: $this->description,
            categoryId: $this->category_id,
            contactId: $this->contact_id,
            allocation: $this->allocation === null ? null : Reference::fromString($this->allocation),
            isTaxable: $this->is_taxable,
            notes: $this->notes,
        );
    }
}
