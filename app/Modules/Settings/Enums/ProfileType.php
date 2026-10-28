<?php

declare(strict_types=1);

namespace App\Modules\Settings\Enums;

enum ProfileType: string
{
    case Individual = 'individual';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Persona fisica (ditta individuale / professionista)',
            self::Company => 'Società',
        };
    }
}
