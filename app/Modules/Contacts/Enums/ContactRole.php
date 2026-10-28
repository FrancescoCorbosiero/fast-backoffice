<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Enums;

/**
 * What a contact is to the studio. A contact may hold several roles.
 */
enum ContactRole: string
{
    case Client = 'client';
    case Supplier = 'supplier';
    case Partner = 'partner';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Cliente',
            self::Supplier => 'Fornitore',
            self::Partner => 'Collaboratore',
            self::Other => 'Altro',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Client => 'success',
            self::Supplier => 'warning',
            self::Partner => 'info',
            self::Other => 'gray',
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
