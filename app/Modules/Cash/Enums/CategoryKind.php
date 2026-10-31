<?php

declare(strict_types=1);

namespace App\Modules\Cash\Enums;

enum CategoryKind: string
{
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Entrate',
            self::Expense => 'Uscite',
        };
    }

    public function direction(): Direction
    {
        return $this === self::Income ? Direction::In : Direction::Out;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [self::Income->value => self::Income->label(), self::Expense->value => self::Expense->label()];
    }
}
