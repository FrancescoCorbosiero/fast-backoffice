<?php

declare(strict_types=1);

namespace App\Modules\Domains\Enums;

enum DomainStatus: string
{
    case Active = 'active';
    case Transferred = 'transferred';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Attivo',
            self::Transferred => 'Trasferito',
            self::Released => 'Rilasciato',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Transferred => 'info',
            self::Released => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
