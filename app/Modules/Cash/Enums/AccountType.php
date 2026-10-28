<?php

declare(strict_types=1);

namespace App\Modules\Cash\Enums;

enum AccountType: string
{
    case Bank = 'bank';
    case Card = 'card';
    case Cash = 'cash';
    case PayPal = 'paypal';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Conto bancario',
            self::Card => 'Carta',
            self::Cash => 'Contanti',
            self::PayPal => 'PayPal',
            self::Other => 'Altro',
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
