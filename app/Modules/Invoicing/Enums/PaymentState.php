<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Enums;

use Carbon\CarbonImmutable;

/**
 * Derived from the cash movements allocated to the document, the total
 * and the due date, with explicit precedence: Paid > Overdue >
 * PartiallyPaid > Unpaid. A partly paid invoice past its due date is late
 * money, so it is Overdue; an invoice is late from the day after its due
 * date.
 */
enum PaymentState: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Da incassare',
            self::PartiallyPaid => 'Parzialmente incassata',
            self::Paid => 'Incassata',
            self::Overdue => 'Scaduta',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'gray',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Overdue => 'danger',
        };
    }

    public static function derive(int $totalCents, int $paidCents, ?CarbonImmutable $dueOn, CarbonImmutable $today): self
    {
        return match (true) {
            $totalCents > 0 && $paidCents >= $totalCents => self::Paid,
            $dueOn !== null && $dueOn->lessThan($today->startOfDay()) => self::Overdue,
            $paidCents > 0 => self::PartiallyPaid,
            default => self::Unpaid,
        };
    }
}
