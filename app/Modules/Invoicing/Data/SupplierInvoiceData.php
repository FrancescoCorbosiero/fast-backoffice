<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Invoicing\Enums\DocumentType;
use Carbon\CarbonImmutable;

/**
 * An invoice received from a supplier: a payable.
 */
final readonly class SupplierInvoiceData
{
    /**
     * @param  list<LineInput>  $lines
     */
    public function __construct(
        public int $id,
        public int $contactId,
        public string $supplierName,
        public DocumentType $type,
        public string $number,
        public CarbonImmutable $issuedOn,
        public ?CarbonImmutable $dueOn,
        public string $currency,
        public array $lines,
        public DocumentTotals $totals,
        public Money $total,
        public Money $paid,
        public ?string $sdiId,
        public ?string $fileName,
        public ?int $xmlFileId,
        public CarbonImmutable $receivedAt,
    ) {}

    public function reference(): Reference
    {
        return Reference::to('supplier_invoice', $this->id);
    }

    public function outstanding(): Money
    {
        $outstanding = $this->total->minus($this->paid);

        return $outstanding->isNegative() ? Money::zero($this->currency) : $outstanding;
    }

    public function title(): string
    {
        return $this->type->label().' '.$this->number.' di '.$this->supplierName;
    }
}
