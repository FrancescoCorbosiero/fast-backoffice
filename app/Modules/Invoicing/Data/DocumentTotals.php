<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Money\Money;
use App\Kernel\Money\VatRate;
use App\Kernel\Vat\Natura;
use App\Support\Shape;

/**
 * A document's figures, computed once by the Kernel's engine and frozen
 * at issue: the lines' net, the INPS contribution (rivalsa) when it
 * applies, the VAT summary per (rate, natura), the stamp duty, and the
 * total the customer owes.
 */
final readonly class DocumentTotals
{
    /**
     * @param  list<VatSummaryLine>  $summary
     */
    public function __construct(
        public Money $subtotal,
        public Money $inps,
        public int $inpsBasisPoints,
        public ?VatRate $inpsVatRate,
        public ?Natura $inpsNatura,
        public array $summary,
        public Money $vat,
        public bool $stampDutyDue,
        public Money $stampDutyCharged,
        public Money $total,
    ) {}

    /**
     * The taxable base: lines plus the INPS contribution.
     */
    public function taxable(): Money
    {
        return $this->subtotal->plus($this->inps);
    }

    public function hasInps(): bool
    {
        return $this->inps->isPositive();
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, string $currency): self
    {
        $inpsNatura = Shape::nullableString($data, 'inps_natura');
        $inpsRate = $data['inps_vat_basis_points'] ?? null;

        return new self(
            subtotal: new Money(Shape::int($data, 'subtotal_cents'), $currency),
            inps: new Money(Shape::int($data, 'inps_cents'), $currency),
            inpsBasisPoints: Shape::int($data, 'inps_basis_points'),
            inpsVatRate: is_numeric($inpsRate) ? new VatRate((int) $inpsRate) : null,
            inpsNatura: $inpsNatura === null ? null : Natura::tryFrom($inpsNatura),
            summary: array_map(fn (array $line): VatSummaryLine => VatSummaryLine::fromArray($line, $currency), Shape::listOfArrays($data, 'summary')),
            vat: new Money(Shape::int($data, 'vat_cents'), $currency),
            stampDutyDue: Shape::bool($data, 'stamp_duty_due'),
            stampDutyCharged: new Money(Shape::int($data, 'stamp_duty_charged_cents'), $currency),
            total: new Money(Shape::int($data, 'total_cents'), $currency),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subtotal_cents' => $this->subtotal->cents,
            'inps_cents' => $this->inps->cents,
            'inps_basis_points' => $this->inpsBasisPoints,
            'inps_vat_basis_points' => $this->inpsVatRate?->basisPoints,
            'inps_natura' => $this->inpsNatura?->value,
            'summary' => array_map(fn (VatSummaryLine $line): array => $line->toArray(), $this->summary),
            'vat_cents' => $this->vat->cents,
            'stamp_duty_due' => $this->stampDutyDue,
            'stamp_duty_charged_cents' => $this->stampDutyCharged->cents,
            'total_cents' => $this->total->cents,
        ];
    }
}
