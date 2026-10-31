<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Kernel\Money\Money;
use App\Modules\Invoicing\Data\DocumentTotals;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Data\PartySnapshot;
use App\Modules\Invoicing\Database\Factories\InvoiceFactory;
use App\Modules\Invoicing\Enums\DocumentType;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\SendChannel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A sales invoice or credit note (design.md §5.3). Drafts are editable;
 * from issue on, the lines, snapshots and totals never change.
 *
 * @property int $id
 * @property DocumentType $type
 * @property InvoiceStatus $status
 * @property string $series
 * @property ?int $year
 * @property ?int $number
 * @property ?string $number_text
 * @property ?CarbonImmutable $issued_on
 * @property ?CarbonImmutable $due_on
 * @property int $contact_id
 * @property string $customer_name
 * @property ?int $related_invoice_id
 * @property string $currency
 * @property list<array<string, mixed>> $lines
 * @property int $payment_terms_days
 * @property ?string $notes
 * @property ?array<string, mixed> $seller
 * @property ?array<string, mixed> $customer
 * @property ?string $regime
 * @property ?array<string, mixed> $totals
 * @property int $total_cents
 * @property int $paid_cents
 * @property ?string $sdi_file_name
 * @property ?string $progressivo_invio
 * @property ?int $xml_file_id
 * @property ?int $pdf_file_id
 * @property ?CarbonImmutable $issued_at
 * @property ?CarbonImmutable $sent_at
 * @property ?SendChannel $sent_channel
 * @property ?CarbonImmutable $voided_at
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'total_cents' => 0,
        'paid_cents' => 0,
        'currency' => 'EUR',
        'payment_terms_days' => 30,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => InvoiceStatus::class,
            'year' => 'integer',
            'number' => 'integer',
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'lines' => 'array',
            'payment_terms_days' => 'integer',
            'seller' => 'array',
            'customer' => 'array',
            'totals' => 'array',
            'total_cents' => 'integer',
            'paid_cents' => 'integer',
            'issued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'sent_channel' => SendChannel::class,
            'voided_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', '!=', InvoiceStatus::Draft->value);
    }

    /**
     * Issued documents the customer still owes on (or is owed).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value, InvoiceStatus::Rejected->value])
            ->whereColumn('paid_cents', '<', 'total_cents');
    }

    /**
     * @return list<LineInput>
     */
    public function lineInputs(): array
    {
        return array_map(fn (array $line): LineInput => LineInput::fromArray($line, $this->currency), $this->lines);
    }

    public function totalsData(): ?DocumentTotals
    {
        return $this->totals === null ? null : DocumentTotals::fromArray($this->totals, $this->currency);
    }

    public function toData(): InvoiceData
    {
        return new InvoiceData(
            id: $this->id,
            type: $this->type,
            status: $this->status,
            series: $this->series,
            year: $this->year,
            number: $this->number,
            numberText: $this->number_text,
            issuedOn: $this->issued_on,
            dueOn: $this->due_on,
            contactId: $this->contact_id,
            customerName: $this->customer_name,
            relatedInvoiceId: $this->related_invoice_id,
            currency: $this->currency,
            lines: $this->lineInputs(),
            paymentTermsDays: $this->payment_terms_days,
            notes: $this->notes,
            seller: $this->seller === null ? null : PartySnapshot::fromArray($this->seller),
            customer: $this->customer === null ? null : PartySnapshot::fromArray($this->customer),
            regime: $this->regime,
            totals: $this->totalsData(),
            total: new Money($this->total_cents, $this->currency),
            paid: new Money($this->paid_cents, $this->currency),
            sdiFileName: $this->sdi_file_name,
            progressivoInvio: $this->progressivo_invio,
            xmlFileId: $this->xml_file_id,
            pdfFileId: $this->pdf_file_id,
            sentAt: $this->sent_at,
            sentChannel: $this->sent_channel,
        );
    }
}
