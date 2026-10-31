<?php

declare(strict_types=1);

namespace App\Modules\Domains\Models;

use App\Kernel\Dates\Months;
use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Domains\Data\DomainData;
use App\Modules\Domains\Database\Factories\DomainFactory;
use App\Modules\Domains\Enums\DomainStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property int $contact_id
 * @property ?int $website_id
 * @property ?string $registrar
 * @property DomainStatus $status
 * @property ?CarbonImmutable $registered_on
 * @property CarbonImmutable $expires_on
 * @property int $renewal_months
 * @property bool $auto_renew
 * @property ?int $renewal_cost_cents
 * @property string $currency
 * @property ?int $account_id
 * @property ?int $category_id
 * @property ?string $notes
 */
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'EUR',
        'renewal_months' => 12,
        'auto_renew' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'registered_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'renewal_months' => 'integer',
            'auto_renew' => 'boolean',
            'renewal_cost_cents' => 'integer',
        ];
    }

    protected static function newFactory(): DomainFactory
    {
        return DomainFactory::new();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', DomainStatus::Active->value);
    }

    public function renewalCost(): ?Money
    {
        return $this->renewal_cost_cents === null ? null : new Money($this->renewal_cost_cents, $this->currency);
    }

    /**
     * The step rule (design.md §5.5): the expiry moves by whole months, no overflow.
     */
    public function expiryAfter(CarbonImmutable $expiresOn): CarbonImmutable
    {
        return Months::add($expiresOn, $this->renewal_months);
    }

    /**
     * The allocation of the movement paying one renewal: once per period.
     */
    public function allocationFor(CarbonImmutable $expiresOn): Reference
    {
        return Reference::to('domain_renewal', $this->id.':'.$expiresOn->toDateString());
    }

    public function toData(): DomainData
    {
        return new DomainData(
            id: $this->id,
            name: $this->name,
            contactId: $this->contact_id,
            websiteId: $this->website_id,
            registrar: $this->registrar,
            status: $this->status,
            registeredOn: $this->registered_on,
            expiresOn: $this->expires_on,
            renewalMonths: $this->renewal_months,
            autoRenew: $this->auto_renew,
            renewalCost: $this->renewalCost(),
            accountId: $this->account_id,
            categoryId: $this->category_id,
            notes: $this->notes,
        );
    }
}
