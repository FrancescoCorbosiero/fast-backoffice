<?php

declare(strict_types=1);

namespace App\Modules\Settings\Enums;

/**
 * The seller's tax regime, as FatturaPA's RegimeFiscale codes it. v1
 * supports the ordinary and the flat-rate regime (sdi.md §1).
 */
enum RegimeFiscale: string
{
    case Ordinario = 'RF01';
    case Forfettario = 'RF19';

    public function label(): string
    {
        return match ($this) {
            self::Ordinario => 'RF01 – Ordinario',
            self::Forfettario => 'RF19 – Regime forfettario',
        };
    }

    public function chargesVat(): bool
    {
        return $this === self::Ordinario;
    }
}
