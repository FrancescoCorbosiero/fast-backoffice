<?php

declare(strict_types=1);

namespace App\Modules\Mail\Data;

use Carbon\CarbonImmutable;

/**
 * An SES event as SNS delivers it (eventType, or notificationType in the
 * older feedback format): what happened to which message.
 */
final readonly class SesEvent
{
    /**
     * @param  list<string>  $recipients  the addresses the event is about
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public ?string $sesMessageId,
        public ?string $headerMessageId,
        public ?string $bounceType,
        public ?string $bounceSubType,
        public array $recipients,
        public ?CarbonImmutable $occurredAt,
        public array $payload,
    ) {}

    public function isPermanentBounce(): bool
    {
        return $this->type === 'Bounce' && $this->bounceType === 'Permanent';
    }

    public function isTransientBounce(): bool
    {
        return $this->type === 'Bounce' && ! $this->isPermanentBounce();
    }
}
