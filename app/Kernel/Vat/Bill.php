<?php

declare(strict_types=1);

namespace App\Kernel\Vat;

use App\Kernel\Money\Money;
use InvalidArgumentException;

/**
 * The VAT engine: the single home of bill arithmetic. Quotes, invoices,
 * credit notes, recurring schedules, the XML, the PDFs and the dashboards
 * all compute totals here.
 *
 * Rules, chosen to match what SdI validates:
 *   - a line's net is quantity × unit price, rounded half away from zero
 *     to the cent;
 *   - VAT is computed once per (rate, natura) group on the group's summed
 *     net, the DatiRiepilogo rule. Rounding per line and adding the
 *     results drifts by a cent every few lines, and SdI rejects an Imposta
 *     that doesn't match ImponibileImporto × AliquotaIVA;
 *   - integers only.
 */
final readonly class Bill
{
    /** @var list<Line> */
    public array $lines;

    /**
     * @param  iterable<Line>  $lines
     */
    public function __construct(iterable $lines, public string $currency = 'EUR')
    {
        $list = [];
        foreach ($lines as $line) {
            if ($line->unitPrice->currency !== $currency) {
                throw new InvalidArgumentException("Line \"{$line->description}\" is in {$line->unitPrice->currency}, the bill in {$currency}.");
            }
            $list[] = $line;
        }

        $this->lines = $list;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * The VAT summary, one group per (rate, natura), in order of first
     * appearance.
     *
     * @return list<VatGroup>
     */
    public function summary(): array
    {
        /** @var array<string, array{line: Line, taxable: Money}> $groups */
        $groups = [];

        foreach ($this->lines as $line) {
            $key = VatGroup::keyFor($line->vatRate, $line->natura);
            $groups[$key] ??= ['line' => $line, 'taxable' => Money::zero($this->currency)];
            $groups[$key]['taxable'] = $groups[$key]['taxable']->plus($line->net);
        }

        return array_values(array_map(
            fn (array $group): VatGroup => new VatGroup(
                vatRate: $group['line']->vatRate,
                natura: $group['line']->natura,
                taxable: $group['taxable'],
                vat: $group['line']->vatRate->on($group['taxable']),
            ),
            $groups,
        ));
    }

    public function subtotal(): Money
    {
        return Money::sum(array_map(fn (Line $line): Money => $line->net, $this->lines), $this->currency);
    }

    public function vat(): Money
    {
        return Money::sum(array_map(fn (VatGroup $group): Money => $group->vat, $this->summary()), $this->currency);
    }

    public function total(): Money
    {
        return $this->subtotal()->plus($this->vat());
    }
}
