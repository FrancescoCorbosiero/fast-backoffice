<?php

declare(strict_types=1);

namespace App\Kernel\Vat;

use App\Kernel\Money\Money;
use App\Kernel\Money\Quantity;
use App\Kernel\Money\VatRate;
use InvalidArgumentException;

/**
 * One line of a bill (quote, invoice, credit note, recurring schedule):
 * its net is quantity × unit price rounded to the cent. A 0% line names
 * its natura; a taxed line never carries one.
 */
final readonly class Line
{
    public Money $net;

    public function __construct(
        public string $description,
        public Quantity $quantity,
        public Money $unitPrice,
        public VatRate $vatRate,
        public ?Natura $natura = null,
    ) {
        if ($vatRate->isZero() && $natura === null) {
            throw new InvalidArgumentException("A 0% line needs a natura: \"{$description}\".");
        }

        if (! $vatRate->isZero() && $natura !== null) {
            throw new InvalidArgumentException("A taxed line cannot carry a natura: \"{$description}\".");
        }

        $this->net = $quantity->times($unitPrice);
    }
}
