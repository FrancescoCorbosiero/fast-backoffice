<?php

declare(strict_types=1);

namespace App\Modules\Cash\Models;

use App\Kernel\Money\Money;
use App\Modules\Cash\Data\AccountData;
use App\Modules\Cash\Database\Factories\AccountFactory;
use App\Modules\Cash\Enums\AccountType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property AccountType $type
 * @property ?string $iban
 * @property string $currency
 * @property int $opening_balance_cents
 * @property CarbonImmutable $opened_on
 * @property bool $is_active
 * @property int $sort
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $table = 'cash_accounts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'opening_balance_cents' => 'integer',
            'opened_on' => 'immutable_date',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }

    /**
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function openingBalance(): Money
    {
        return new Money($this->opening_balance_cents, $this->currency);
    }

    public function toData(Money $balance): AccountData
    {
        return new AccountData(
            id: $this->id,
            name: $this->name,
            type: $this->type,
            iban: $this->iban,
            currency: $this->currency,
            openingBalance: $this->openingBalance(),
            openedOn: $this->opened_on,
            isActive: $this->is_active,
            balance: $balance,
        );
    }
}
