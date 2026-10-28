<?php

declare(strict_types=1);

namespace App\Kernel\Vat;

use App\Kernel\Money\Money;
use App\Kernel\Money\VatRate;

/**
 * One row of the VAT summary: every line sharing a rate (and, at 0%, a
 * natura) collapses into one taxable amount and one VAT amount. Maps 1:1
 * onto a FatturaPA DatiRiepilogo block.
 */
final readonly class VatGroup
{
    public function __construct(
        public VatRate $vatRate,
        public ?Natura $natura,
        public Money $taxable,
        public Money $vat,
    ) {}

    public function key(): string
    {
        return self::keyFor($this->vatRate, $this->natura);
    }

    public static function keyFor(VatRate $vatRate, ?Natura $natura): string
    {
        return $vatRate->basisPoints.'|'.($natura === null ? '' : $natura->value);
    }
}
