<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Kernel\Money\Money;
use App\Modules\Invoicing\Data\DocumentTotals;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Data\SupplierInvoiceData;
use App\Modules\Invoicing\Database\Factories\SupplierInvoiceFactory;
use App\Modules\Invoicing\Enums\DocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $contact_id
 * @property string $supplier_name
 * @property string $fingerprint
 * @property DocumentType $type
 * @property string $number
 * @property CarbonImmutable $issued_on
 * @property ?CarbonImmutable $due_on
 * @property string $currency
 * @property list<array<string, mixed>> $lines
 * @property array<string, mixed> $totals
 * @property int $total_cents
 * @property int $paid_cents
 * @property ?string $sdi_id
 * @property ?string $file_name
 * @property ?int $xml_file_id
 * @property CarbonImmutable $received_at
 */
class SupplierInvoice extends Model
{
    /** @use HasFactory<SupplierInvoiceFactory> */
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
            'type' => DocumentType::class,
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'lines' => 'array',
            'totals' => 'array',
            'total_cents' => 'integer',
            'paid_cents' => 'integer',
            'received_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): SupplierInvoiceFactory
    {
        return SupplierInvoiceFactory::new();
    }

    public function toData(): SupplierInvoiceData
    {
        return new SupplierInvoiceData(
            id: $this->id,
            contactId: $this->contact_id,
            supplierName: $this->supplier_name,
            type: $this->type,
            number: $this->number,
            issuedOn: $this->issued_on,
            dueOn: $this->due_on,
            currency: $this->currency,
            lines: array_map(fn (array $line): LineInput => LineInput::fromArray($line, $this->currency), $this->lines),
            totals: DocumentTotals::fromArray($this->totals, $this->currency),
            total: new Money($this->total_cents, $this->currency),
            paid: new Money($this->paid_cents, $this->currency),
            sdiId: $this->sdi_id,
            fileName: $this->file_name,
            xmlFileId: $this->xml_file_id,
            receivedAt: $this->received_at,
        );
    }
}
