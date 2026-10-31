<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Enums;

enum BookingStatus: string
{
    case Accepted = 'accepted';
    case Pending = 'pending';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Confermato',
            self::Pending => 'In attesa',
            self::Rescheduled => 'Spostato',
            self::Cancelled => 'Annullato',
            self::Rejected => 'Rifiutato',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Accepted => 'success',
            self::Pending => 'warning',
            self::Rescheduled => 'info',
            self::Cancelled, self::Rejected => 'gray',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Accepted || $this === self::Pending;
    }

    /**
     * Cal.com's words for a booking's state, in webhooks and the API.
     */
    public static function fromCalCom(?string $status, ?string $trigger = null): self
    {
        $trigger = strtoupper((string) $trigger);
        if ($trigger === 'BOOKING_CANCELLED') {
            return self::Cancelled;
        }
        if ($trigger === 'BOOKING_REJECTED') {
            return self::Rejected;
        }

        return match (strtolower((string) $status)) {
            'cancelled', 'canceled' => self::Cancelled,
            'rejected' => self::Rejected,
            'pending', 'awaiting_host' => self::Pending,
            'rescheduled' => self::Rescheduled,
            default => $trigger === 'BOOKING_RESCHEDULED' ? self::Rescheduled : self::Accepted,
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
