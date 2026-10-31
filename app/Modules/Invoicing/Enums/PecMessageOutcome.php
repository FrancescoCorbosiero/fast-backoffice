<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Enums;

enum PecMessageOutcome: string
{
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Review = 'review';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Processed => 'Elaborato',
            self::Ignored => 'Ignorato',
            self::Review => 'Da verificare',
            self::Failed => 'Errore',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Processed => 'success',
            self::Ignored => 'gray',
            self::Review => 'warning',
            self::Failed => 'danger',
        };
    }
}
