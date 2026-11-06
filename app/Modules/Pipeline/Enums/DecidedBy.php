<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

/**
 * Who approved or rejected a request: the owner in the panel, or the
 * client in the portal.
 */
enum DecidedBy: string
{
    case Owner = 'owner';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Studio',
            self::Client => 'Cliente',
        };
    }
}
