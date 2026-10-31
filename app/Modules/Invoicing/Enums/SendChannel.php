<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Enums;

enum SendChannel: string
{
    case Manual = 'manual';
    case Pec = 'pec';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'portale Agenzia delle Entrate',
            self::Pec => 'PEC',
        };
    }
}
