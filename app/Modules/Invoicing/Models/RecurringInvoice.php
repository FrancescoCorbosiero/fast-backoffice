<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Kernel\Dates\Frequency;
use App\Kernel\Dates\Recurrence;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Data\RecurringInvoiceData;
use App\Modules\Invoicing\Database\Factories\RecurringInvoiceFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A schedule that creates an invoice draft on each date, or issues it
 * when opted in (design.md §5.3).
 *
 * @property int $id
 * @property string $name
 * @property int $contact_id
 * @property Frequency $frequency
 * @property CarbonImmutable $started_on
 * @property CarbonImmutable $next_issue_on
 * @property ?CarbonImmutable $last_issued_on
 * @property list<array<string, mixed>> $lines
 * @property int $payment_terms_days
 * @property bool $auto_issue
 * @property bool $is_active
 * @property ?string $notes
 */
class RecurringInvoice extends Model
{
    /** @use HasFactory<RecurringInvoiceFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => Frequency::class,
            'started_on' => 'immutable_date',
            'next_issue_on' => 'immutable_date',
            'last_issued_on' => 'immutable_date',
            'lines' => 'array',
            'payment_terms_days' => 'integer',
            'auto_issue' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): RecurringInvoiceFactory
    {
        return RecurringInvoiceFactory::new();
    }

    /**
     * @param  Builder<RecurringInvoice>  $query
     * @return Builder<RecurringInvoice>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function recurrence(): Recurrence
    {
        return new Recurrence($this->frequency, $this->started_on);
    }

    public function issueDateAfter(CarbonImmutable $date): CarbonImmutable
    {
        return $this->recurrence()->nextAfter($date);
    }

    /**
     * @return list<LineInput>
     */
    public function lineInputs(): array
    {
        return array_map(fn (array $line): LineInput => LineInput::fromArray($line), $this->lines);
    }

    public function toData(): RecurringInvoiceData
    {
        return new RecurringInvoiceData(
            id: $this->id,
            name: $this->name,
            contactId: $this->contact_id,
            frequency: $this->frequency,
            startedOn: $this->started_on,
            nextIssueOn: $this->next_issue_on,
            lastIssuedOn: $this->last_issued_on,
            lines: $this->lineInputs(),
            paymentTermsDays: $this->payment_terms_days,
            autoIssue: $this->auto_issue,
            isActive: $this->is_active,
        );
    }
}
