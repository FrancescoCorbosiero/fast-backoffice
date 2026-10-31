<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Enums;

/**
 * A person or a company: FatturaPA wants Nome and Cognome for the one,
 * Denominazione for the other.
 */
enum ContactKind: string
{
    case Individual = 'individual';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Persona',
            self::Company => 'Azienda',
        };
    }
}
