<?php

declare(strict_types=1);

namespace App\Kernel\Vat;

/**
 * FatturaPA "Natura": why a line carries no VAT. Required on every 0% line.
 */
enum Natura: string
{
    case N1 = 'N1';
    case N2_1 = 'N2.1';
    case N2_2 = 'N2.2';
    case N3_1 = 'N3.1';
    case N3_2 = 'N3.2';
    case N3_3 = 'N3.3';
    case N3_4 = 'N3.4';
    case N3_5 = 'N3.5';
    case N3_6 = 'N3.6';
    case N4 = 'N4';
    case N5 = 'N5';
    case N6_1 = 'N6.1';
    case N6_2 = 'N6.2';
    case N6_3 = 'N6.3';
    case N6_4 = 'N6.4';
    case N6_5 = 'N6.5';
    case N6_6 = 'N6.6';
    case N6_7 = 'N6.7';
    case N6_8 = 'N6.8';
    case N6_9 = 'N6.9';
    case N7 = 'N7';

    public function label(): string
    {
        return match ($this) {
            self::N1 => 'Escluse ex art. 15',
            self::N2_1 => 'Non soggette ad IVA ai sensi degli artt. da 7 a 7-septies',
            self::N2_2 => 'Non soggette - altri casi (regime forfettario)',
            self::N3_1 => 'Non imponibili - esportazioni',
            self::N3_2 => 'Non imponibili - cessioni intracomunitarie',
            self::N3_3 => 'Non imponibili - cessioni verso San Marino',
            self::N3_4 => 'Non imponibili - operazioni assimilate alle cessioni all\'esportazione',
            self::N3_5 => 'Non imponibili - a seguito di dichiarazioni d\'intento',
            self::N3_6 => 'Non imponibili - altre operazioni che non concorrono alla formazione del plafond',
            self::N4 => 'Esenti',
            self::N5 => 'Regime del margine / IVA non esposta in fattura',
            self::N6_1 => 'Inversione contabile - cessione di rottami e altri materiali di recupero',
            self::N6_2 => 'Inversione contabile - cessione di oro e argento',
            self::N6_3 => 'Inversione contabile - subappalto nel settore edile',
            self::N6_4 => 'Inversione contabile - cessione di fabbricati',
            self::N6_5 => 'Inversione contabile - cessione di telefoni cellulari',
            self::N6_6 => 'Inversione contabile - cessione di prodotti elettronici',
            self::N6_7 => 'Inversione contabile - prestazioni comparto edile e settori connessi',
            self::N6_8 => 'Inversione contabile - operazioni settore energetico',
            self::N6_9 => 'Inversione contabile - altri casi',
            self::N7 => 'IVA assolta in altro stato UE',
        };
    }

    /**
     * Whether an invoice made of lines with this natura owes stamp duty
     * (sdi.md §7). Exports and similar, reverse charge and VAT paid in
     * another EU country do not.
     */
    public function stampDutyApplies(): bool
    {
        return match ($this) {
            self::N1, self::N2_1, self::N2_2, self::N3_5, self::N4 => true,
            default => false,
        };
    }
}
