<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Enums;

/**
 * Expired is never stored: a draft or sent quote past its validity shows
 * as expired (QuoteData::statusOn), and can still be accepted late.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Bozza',
            self::Sent => 'Inviato',
            self::Accepted => 'Accettato',
            self::Declined => 'Rifiutato',
            self::Expired => 'Scaduto',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'info',
            self::Accepted => 'success',
            self::Declined => 'danger',
            self::Expired => 'warning',
        };
    }

    /**
     * Still waiting for the customer's answer.
     */
    public function isOpen(): bool
    {
        return $this === self::Draft || $this === self::Sent;
    }

    /**
     * @return list<self> the ones a quote is stored as
     */
    public static function stored(): array
    {
        return [self::Draft, self::Sent, self::Accepted, self::Declined];
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
