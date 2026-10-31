<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Money\Money;
use App\Kernel\Money\VatRate;
use App\Kernel\Vat\Natura;
use App\Kernel\Vat\VatGroup;
use App\Support\Shape;

/**
 * One DatiRiepilogo block, as stored on the document.
 */
final readonly class VatSummaryLine
{
    public function __construct(
        public VatRate $vatRate,
        public ?Natura $natura,
        public Money $taxable,
        public Money $vat,
    ) {}

    public static function fromGroup(VatGroup $group): self
    {
        return new self($group->vatRate, $group->natura, $group->taxable, $group->vat);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, string $currency): self
    {
        $natura = Shape::nullableString($data, 'natura');

        return new self(
            vatRate: new VatRate(Shape::int($data, 'vat_basis_points')),
            natura: $natura === null ? null : Natura::tryFrom($natura),
            taxable: new Money(Shape::int($data, 'taxable_cents'), $currency),
            vat: new Money(Shape::int($data, 'vat_cents'), $currency),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'vat_basis_points' => $this->vatRate->basisPoints,
            'natura' => $this->natura?->value,
            'taxable_cents' => $this->taxable->cents,
            'vat_cents' => $this->vat->cents,
        ];
    }
}
