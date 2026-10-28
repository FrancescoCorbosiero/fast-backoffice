<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Enums;

enum RunTrigger: string
{
    case Scheduler = 'scheduler';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Scheduler => 'Pianificato',
            self::Manual => 'Manuale',
        };
    }
}
