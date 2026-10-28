<?php

declare(strict_types=1);

namespace App\Modules\Cash\Enums;

/**
 * Money in or money out. Amounts are always positive; the direction says
 * which way they went.
 */
enum Direction: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Entrata',
            self::Out => 'Uscita',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::In => 'success',
            self::Out => 'danger',
        };
    }

    public function sign(): int
    {
        return $this === self::In ? 1 : -1;
    }

    public function categoryKind(): CategoryKind
    {
        return $this === self::In ? CategoryKind::Income : CategoryKind::Expense;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [self::In->value => self::In->label(), self::Out->value => self::Out->label()];
    }
}
