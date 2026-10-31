<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Kernel\Money\Money;
use App\Modules\Invoicing\Data\DocumentTotals;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Data\PaymentRequestData;
use App\Modules\Invoicing\Database\Factories\PaymentRequestFactory;
use App\Modules\Invoicing\Enums\PaymentRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $year
 * @property int $number
 * @property string $number_text
 * @property PaymentRequestStatus $status
 * @property CarbonImmutable $issued_on
 * @property ?CarbonImmutable $due_on
 * @property int $contact_id
 * @property string $customer_name
 * @property string $currency
 * @property list<array<string, mixed>> $lines
 * @property array<string, mixed> $totals
 * @property int $total_cents
 * @property int $paid_cents
 * @property ?CarbonImmutable $paid_on
 * @property ?string $notes
 * @property ?int $invoice_id
 * @property ?int $pdf_file_id
 */
class PaymentRequest extends Model
{
    /** @use HasFactory<PaymentRequestFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'paid_cents' => 0,
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
            'status' => PaymentRequestStatus::class,
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'lines' => 'array',
            'totals' => 'array',
            'total_cents' => 'integer',
            'paid_cents' => 'integer',
            'paid_on' => 'immutable_date',
        ];
    }

    protected static function newFactory(): PaymentRequestFactory
    {
        return PaymentRequestFactory::new();
    }

    /**
     * @return list<LineInput>
     */
    public function lineInputs(): array
    {
        return array_map(fn (array $line): LineInput => LineInput::fromArray($line, $this->currency), $this->lines);
    }

    public function toData(): PaymentRequestData
    {
        return new PaymentRequestData(
            id: $this->id,
            year: $this->year,
            number: $this->number,
            numberText: $this->number_text,
            status: $this->status,
            issuedOn: $this->issued_on,
            dueOn: $this->due_on,
            contactId: $this->contact_id,
            customerName: $this->customer_name,
            currency: $this->currency,
            lines: $this->lineInputs(),
            totals: DocumentTotals::fromArray($this->totals, $this->currency),
            total: new Money($this->total_cents, $this->currency),
            paid: new Money($this->paid_cents, $this->currency),
            paidOn: $this->paid_on,
            notes: $this->notes,
            invoiceId: $this->invoice_id,
        );
    }
}
