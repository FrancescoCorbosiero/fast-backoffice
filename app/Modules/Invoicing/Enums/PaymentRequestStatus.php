<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Enums;

enum PaymentRequestStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Converted = 'converted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aperta',
            self::Paid => 'Pagata',
            self::Converted => 'Fatturata',
            self::Cancelled => 'Annullata',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Paid => 'success',
            self::Converted => 'info',
            self::Cancelled => 'gray',
        };
    }
}
