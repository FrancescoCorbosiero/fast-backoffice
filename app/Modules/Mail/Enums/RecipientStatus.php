<?php

declare(strict_types=1);

namespace App\Modules\Mail\Enums;

enum RecipientStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case SoftBounced = 'soft_bounced';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'In coda',
            self::Sent => 'Inviata',
            self::Delivered => 'Consegnata',
            self::SoftBounced => 'Bounce temporaneo',
            self::Bounced => 'Bounce',
            self::Complained => 'Segnalata come spam',
            self::Failed => 'Fallita',
            self::Skipped => 'Saltata',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Sent => 'info',
            self::Delivered => 'success',
            self::SoftBounced => 'warning',
            self::Bounced, self::Complained, self::Failed => 'danger',
            self::Skipped => 'gray',
        };
    }

    /**
     * Nothing more will happen to this recipient in this campaign.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Queued;
    }
}
