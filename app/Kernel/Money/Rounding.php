<?php

declare(strict_types=1);

namespace App\Kernel\Money;

use InvalidArgumentException;

/**
 * Integer rounding helpers. Every money path rounds half away from zero
 * (commercial rounding, symmetric for negative discount lines), the way
 * SdI checks DatiRiepilogo.
 */
final class Rounding
{
    /**
     * Integer division rounded half away from zero.
     */
    public static function divide(int $dividend, int $divisor): int
    {
        if ($divisor <= 0) {
            throw new InvalidArgumentException('The divisor must be positive.');
        }

        $quotient = intdiv($dividend, $divisor);
        $remainder = abs($dividend % $divisor);

        if ($remainder * 2 >= $divisor) {
            $quotient += $dividend < 0 ? -1 : 1;
        }

        return $quotient;
    }
}
