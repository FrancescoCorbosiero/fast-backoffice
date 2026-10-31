<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Enums;

/**
 * FatturaPA TipoDocumento, for the documents v1 issues.
 */
enum DocumentType: string
{
    case Invoice = 'TD01';
    case CreditNote = 'TD04';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Fattura',
            self::CreditNote => 'Nota di credito',
        };
    }
}
