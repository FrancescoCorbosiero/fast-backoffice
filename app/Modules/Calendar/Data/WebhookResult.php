<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Data;

final readonly class WebhookResult
{
    public function __construct(
        public string $outcome,
        public ?int $bookingId = null,
        public ?string $note = null,
    ) {}

    public function accepted(): bool
    {
        return $this->outcome !== 'rejected';
    }
}
