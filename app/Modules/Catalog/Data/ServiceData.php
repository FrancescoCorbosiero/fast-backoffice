<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Data;

use App\Kernel\Dates\Frequency;
use App\Kernel\Money\Money;
use App\Kernel\Money\VatRate;
use App\Kernel\Vat\Natura;

/**
 * A sellable service: the defaults a document line starts from.
 */
final readonly class ServiceData
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public Money $unitPrice,
        public VatRate $vatRate,
        public ?Natura $natura,
        public ?Frequency $cadence,
        public bool $isActive,
    ) {}

    /**
     * The line a document starts from when this service is picked, in the
     * shape document forms and inputs use.
     *
     * @return array{description: string, quantity: string, unit_price: string, vat_basis_points: int, natura: ?string}
     */
    public function lineTemplate(): array
    {
        return [
            'description' => $this->name.($this->description !== null ? "\n".$this->description : ''),
            'quantity' => '1',
            'unit_price' => $this->unitPrice->toDecimalString(),
            'vat_basis_points' => $this->vatRate->basisPoints,
            'natura' => $this->natura?->value,
        ];
    }
}
