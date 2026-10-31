<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament;

use App\Modules\Invoicing\Data\DocumentTotals;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Data\PartySnapshot;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

/**
 * The read-only rendering of a document's parties, lines and totals,
 * shared by invoices, payment requests and supplier invoices.
 */
final class DocumentInfolist
{
    /**
     * @return list<Component>
     */
    public static function parties(?PartySnapshot $seller, ?PartySnapshot $customer, string $customerLabel = 'Cliente'): array
    {
        $components = [];

        if ($customer !== null) {
            $components[] = Section::make($customerLabel)->compact()->components([
                TextEntry::make('customer_block')->label('')->state(self::party($customer)),
            ]);
        }

        if ($seller !== null) {
            $components[] = Section::make('Emittente')->compact()->components([
                TextEntry::make('seller_block')->label('')->state(self::party($seller)),
            ]);
        }

        return [Grid::make(2)->components($components)];
    }

    /**
     * @param  list<LineInput>  $lines
     */
    public static function lines(array $lines): Component
    {
        $rows = [];
        foreach ($lines as $index => $line) {
            $rows[] = TextEntry::make('line_'.$index)->label('')
                ->state(sprintf('%s — %s × %s (%s) = %s',
                    $line->description,
                    rtrim(rtrim($line->quantity->toDecimalString(), '0'), '.'),
                    $line->unitPrice->format(),
                    $line->natura !== null ? $line->natura->value : 'IVA '.$line->vatRate->toPercentString().'%',
                    $line->net()->format(),
                ));
        }

        return Section::make('Righe')->compact()->components($rows);
    }

    public static function totals(DocumentTotals $totals): Component
    {
        $entries = [TextEntry::make('t_subtotal')->label('Imponibile')->state($totals->subtotal->format())];

        if ($totals->hasInps()) {
            $entries[] = TextEntry::make('t_inps')->label('Rivalsa INPS')->state($totals->inps->format());
        }
        if (! $totals->vat->isZero()) {
            $entries[] = TextEntry::make('t_vat')->label('IVA')->state($totals->vat->format());
        }
        if ($totals->stampDutyDue) {
            $entries[] = TextEntry::make('t_stamp')->label('Imposta di bollo')->state($totals->stampDutyCharged->isPositive() ? $totals->stampDutyCharged->format().' (addebitata)' : '€ 2,00 (assolta dall\'emittente)');
        }
        $entries[] = TextEntry::make('t_total')->label('Totale')->state($totals->total->format())->weight('bold')->size('lg');

        return Section::make('Totali')->compact()->columns(count($entries))->components($entries);
    }

    private static function party(PartySnapshot $party): string
    {
        $lines = [$party->name];
        if ($party->vatNumber !== null) {
            $lines[] = 'P. IVA '.$party->vatNumber->toString();
        }
        if ($party->taxCode !== null) {
            $lines[] = 'C.F. '.$party->taxCode;
        }
        $lines[] = $party->addressLine();
        if ($party->sdiCode !== null) {
            $lines[] = 'SdI '.$party->sdiCode;
        }
        if ($party->pec !== null) {
            $lines[] = 'PEC '.$party->pec;
        }

        return implode("\n", array_filter($lines, fn (string $line): bool => $line !== ''));
    }
}
