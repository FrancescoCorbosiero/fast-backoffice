<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Kernel\Money\Money;
use App\Modules\Leads\Data\LeadData;
use App\Modules\Leads\Database\Factories\LeadFactory;
use App\Modules\Leads\Enums\LeadStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property ?string $company
 * @property ?string $email
 * @property ?string $phone
 * @property ?string $source
 * @property LeadStatus $status
 * @property ?int $estimated_value_cents
 * @property string $currency
 * @property ?CarbonImmutable $next_follow_up_on
 * @property ?string $notes
 * @property ?int $contact_id
 * @property ?int $quote_id
 * @property ?int $website_id
 * @property ?CarbonImmutable $converted_at
 * @property ?string $lost_reason
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['currency' => 'EUR'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'estimated_value_cents' => 'integer',
            'next_follow_up_on' => 'immutable_date',
            'converted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(fn (LeadStatus $s): string => $s->value, LeadStatus::open()));
    }

    public function toData(): LeadData
    {
        return new LeadData(
            id: $this->id,
            name: $this->name,
            company: $this->company,
            email: $this->email,
            phone: $this->phone,
            source: $this->source,
            status: $this->status,
            estimatedValue: $this->estimated_value_cents === null ? null : new Money($this->estimated_value_cents, $this->currency),
            nextFollowUpOn: $this->next_follow_up_on,
            notes: $this->notes,
            contactId: $this->contact_id,
            quoteId: $this->quote_id,
            websiteId: $this->website_id,
            convertedAt: $this->converted_at,
            lostReason: $this->lost_reason,
        );
    }
}
