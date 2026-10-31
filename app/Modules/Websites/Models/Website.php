<?php

declare(strict_types=1);

namespace App\Modules\Websites\Models;

use App\Kernel\Dates\Frequency;
use App\Kernel\Dates\Recurrence;
use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Websites\Data\WebsiteData;
use App\Modules\Websites\Database\Factories\WebsiteFactory;
use App\Modules\Websites\Enums\WebsiteStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $contact_id
 * @property string $name
 * @property string $url
 * @property string $host
 * @property WebsiteStatus $status
 * @property ?string $hosting_provider
 * @property ?CarbonImmutable $hosting_started_on
 * @property ?CarbonImmutable $hosting_renews_on
 * @property ?Frequency $hosting_frequency
 * @property ?int $hosting_cost_cents
 * @property string $currency
 * @property ?int $hosting_account_id
 * @property ?int $hosting_category_id
 * @property ?int $subscription_id
 * @property bool $uptime_enabled
 * @property ?CarbonImmutable $last_checked_at
 * @property ?bool $is_up
 * @property ?int $last_status_code
 * @property ?int $last_response_ms
 * @property int $consecutive_failures
 * @property ?CarbonImmutable $down_since
 * @property ?string $notes
 */
class Website extends Model
{
    /** @use HasFactory<WebsiteFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'EUR',
        'uptime_enabled' => true,
        'consecutive_failures' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebsiteStatus::class,
            'hosting_started_on' => 'immutable_date',
            'hosting_renews_on' => 'immutable_date',
            'hosting_frequency' => Frequency::class,
            'hosting_cost_cents' => 'integer',
            'uptime_enabled' => 'boolean',
            'last_checked_at' => 'immutable_datetime',
            'is_up' => 'boolean',
            'last_status_code' => 'integer',
            'last_response_ms' => 'integer',
            'consecutive_failures' => 'integer',
            'down_since' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): WebsiteFactory
    {
        return WebsiteFactory::new();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', WebsiteStatus::Active->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMonitored(Builder $query): Builder
    {
        return $query->active()->where('uptime_enabled', true);
    }

    public function hostingCost(): ?Money
    {
        return $this->hosting_cost_cents === null ? null : new Money($this->hosting_cost_cents, $this->currency);
    }

    /**
     * The hosting step rule (design.md §5.5): anchored on the first renewal.
     */
    public function hostingRecurrence(): Recurrence
    {
        $frequency = $this->hosting_frequency ?? throw new LogicException("Website {$this->id} has no hosting cadence.");
        $anchor = $this->hosting_started_on ?? $this->hosting_renews_on ?? throw new LogicException("Website {$this->id} has no hosting date.");

        return new Recurrence($frequency, $anchor);
    }

    public function hostingRenewalAfter(CarbonImmutable $date): CarbonImmutable
    {
        return $this->hostingRecurrence()->nextAfter($date);
    }

    /**
     * The allocation of the movement paying one renewal: once per period.
     */
    public function hostingAllocationFor(CarbonImmutable $renewsOn): Reference
    {
        return Reference::to('hosting_renewal', $this->id.':'.$renewsOn->toDateString());
    }

    public function toData(): WebsiteData
    {
        return new WebsiteData(
            id: $this->id,
            contactId: $this->contact_id,
            name: $this->name,
            url: $this->url,
            host: $this->host,
            status: $this->status,
            hostingProvider: $this->hosting_provider,
            hostingStartedOn: $this->hosting_started_on,
            hostingRenewsOn: $this->hosting_renews_on,
            hostingFrequency: $this->hosting_frequency,
            hostingCost: $this->hostingCost(),
            hostingAccountId: $this->hosting_account_id,
            hostingCategoryId: $this->hosting_category_id,
            subscriptionId: $this->subscription_id,
            uptimeEnabled: $this->uptime_enabled,
            lastCheckedAt: $this->last_checked_at,
            isUp: $this->is_up,
            lastStatusCode: $this->last_status_code,
            lastResponseMs: $this->last_response_ms,
            consecutiveFailures: $this->consecutive_failures,
            downSince: $this->down_since,
            notes: $this->notes,
        );
    }
}
