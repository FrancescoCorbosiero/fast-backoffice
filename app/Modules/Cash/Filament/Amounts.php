<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament;

use App\Kernel\Money\Money;
use Closure;
use Filament\Forms\Components\TextInput;
use InvalidArgumentException;

/**
 * Money on the Cash screens: a decimal field in, Money out.
 */
final class Amounts
{
    public static function input(string $name, string $label = 'Importo'): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required()
            ->prefix('€')
            ->placeholder('0,00')
            ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    $money = Money::fromDecimal(is_scalar($value) ? (string) $value : '');
                } catch (InvalidArgumentException) {
                    $fail('Scrivi un importo con al massimo due decimali.');

                    return;
                }

                if (! $money->isPositive()) {
                    $fail('L\'importo è maggiore di zero.');
                }
            }]);
    }

    public static function cents(mixed $decimal): int
    {
        return Money::fromDecimal(is_scalar($decimal) ? (string) $decimal : '0')->cents;
    }

    public static function decimal(mixed $cents): string
    {
        return (new Money(is_numeric($cents) ? (int) $cents : 0))->toDecimalString();
    }

    public static function format(Money $money): string
    {
        return $money->format();
    }
}
