<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Enums;

/**
 * The lifecycle of design.md §5.3. Payment is a separate state.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case SentManually = 'sent_manually';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case NotDelivered = 'not_delivered';
    case Rejected = 'rejected';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Bozza',
            self::Issued => 'Emessa, da inviare',
            self::SentManually => 'Inviata manualmente',
            self::Sent => 'Inviata via PEC',
            self::Delivered => 'Consegnata',
            self::NotDelivered => 'Non consegnata',
            self::Rejected => 'Scartata',
            self::Void => 'Annullata',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Issued => 'warning',
            self::SentManually, self::Sent => 'info',
            self::Delivered => 'success',
            self::NotDelivered, self::Rejected, self::Void => 'danger',
        };
    }

    /**
     * The content is frozen from here on.
     */
    public function isIssued(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Issued, and a number the customer owes money on (or is owed, for a credit note).
     */
    public function isOutstanding(): bool
    {
        return $this->isIssued() && ! in_array($this, [self::Void, self::Rejected], true);
    }

    public function hasReachedSdi(): bool
    {
        return in_array($this, [self::SentManually, self::Sent, self::Delivered, self::NotDelivered], true);
    }

    public function canBeSent(): bool
    {
        return $this === self::Issued;
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
