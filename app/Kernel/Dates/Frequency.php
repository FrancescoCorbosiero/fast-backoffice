<?php

declare(strict_types=1);

namespace App\Kernel\Dates;

/**
 * How often something recurs. Every cadence is a whole number of months,
 * so stepping never depends on the length of the month in between.
 */
enum Frequency: string
{
    case Monthly = 'monthly';
    case Bimonthly = 'bimonthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Yearly = 'yearly';

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Bimonthly => 2,
            self::Quarterly => 3,
            self::Semiannual => 6,
            self::Yearly => 12,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensile',
            self::Bimonthly => 'Bimestrale',
            self::Quarterly => 'Trimestrale',
            self::Semiannual => 'Semestrale',
            self::Yearly => 'Annuale',
        };
    }
}
