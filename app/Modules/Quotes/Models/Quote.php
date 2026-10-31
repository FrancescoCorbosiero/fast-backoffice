<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Models;

use App\Kernel\Money\Money;
use App\Modules\Invoicing\Data\DocumentTotals;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Quotes\Data\QuoteData;
use App\Modules\Quotes\Database\Factories\QuoteFactory;
use App\Modules\Quotes\Enums\QuoteStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $year
 * @property int $number
 * @property string $number_text
 * @property QuoteStatus $status
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable $valid_until
 * @property int $contact_id
 * @property string $customer_name
 * @property ?string $subject
 * @property string $currency
 * @property list<array<string, mixed>> $lines
 * @property array<string, mixed> $totals
 * @property int $total_cents
 * @property ?string $notes
 * @property ?CarbonImmutable $sent_on
 * @property ?CarbonImmutable $decided_on
 * @property ?string $decline_reason
 * @property ?int $invoice_id
 * @property ?int $payment_request_id
 * @property ?int $pdf_file_id
 */
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'EUR',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'number' => 'integer',
            'status' => QuoteStatus::class,
            'issued_on' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'lines' => 'array',
            'totals' => 'array',
            'total_cents' => 'integer',
            'sent_on' => 'immutable_date',
            'decided_on' => 'immutable_date',
        ];
    }

    protected static function newFactory(): QuoteFactory
    {
        return QuoteFactory::new();
    }

    /**
     * @return list<LineInput>
     */
    public function lineInputs(): array
    {
        return array_map(fn (array $line): LineInput => LineInput::fromArray($line, $this->currency), $this->lines);
    }

    public function toData(): QuoteData
    {
        return new QuoteData(
            id: $this->id,
            year: $this->year,
            number: $this->number,
            numberText: $this->number_text,
            status: $this->status,
            issuedOn: $this->issued_on,
            validUntil: $this->valid_until,
            contactId: $this->contact_id,
            customerName: $this->customer_name,
            subject: $this->subject,
            currency: $this->currency,
            lines: $this->lineInputs(),
            totals: DocumentTotals::fromArray($this->totals, $this->currency),
            total: new Money($this->total_cents, $this->currency),
            notes: $this->notes,
            sentOn: $this->sent_on,
            decidedOn: $this->decided_on,
            declineReason: $this->decline_reason,
            invoiceId: $this->invoice_id,
            paymentRequestId: $this->payment_request_id,
            pdfFileId: $this->pdf_file_id,
        );
    }
}
