<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Money\Money;
use App\Kernel\Money\Quantity;
use App\Kernel\Money\VatRate;
use App\Kernel\Vat\Line;
use App\Kernel\Vat\Natura;
use App\Support\Shape;
use InvalidArgumentException;

/**
 * One line of a document, as written and stored (JSON). Money as integer
 * cents, quantities as decimal strings, rates in basis points.
 */
final readonly class LineInput
{
    public function __construct(
        public string $description,
        public Quantity $quantity,
        public Money $unitPrice,
        public VatRate $vatRate,
        public ?Natura $natura = null,
    ) {}

    /**
     * From form state or storage: "quantity" and "unit_price" as decimal
     * strings, "vat_basis_points" as an integer, "natura" as a code.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, string $currency = 'EUR'): self
    {
        $natura = Shape::nullableString($data, 'natura');

        return new self(
            description: trim(Shape::string($data, 'description')),
            quantity: Quantity::fromDecimal(Shape::nullableString($data, 'quantity') ?? '1'),
            unitPrice: Money::fromDecimal(Shape::nullableString($data, 'unit_price') ?? '0', $currency),
            vatRate: new VatRate(Shape::int($data, 'vat_basis_points')),
            natura: $natura === null ? null : (Natura::tryFrom($natura) ?? throw new InvalidArgumentException("\"{$natura}\" is not a natura code.")),
        );
    }

    /**
     * @return array{description: string, quantity: string, unit_price: string, vat_basis_points: int, natura: ?string}
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'quantity' => $this->quantity->toDecimalString(),
            'unit_price' => $this->unitPrice->toDecimalString(),
            'vat_basis_points' => $this->vatRate->basisPoints,
            'natura' => $this->natura?->value,
        ];
    }

    public function withNatura(?Natura $natura): self
    {
        return new self($this->description, $this->quantity, $this->unitPrice, $this->vatRate, $natura);
    }

    /**
     * The Kernel line: validates natura against the rate and computes the net.
     */
    public function toKernelLine(): Line
    {
        return new Line($this->description, $this->quantity, $this->unitPrice, $this->vatRate, $this->natura);
    }

    public function net(): Money
    {
        return $this->quantity->times($this->unitPrice);
    }
}
