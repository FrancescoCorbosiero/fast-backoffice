<?php

declare(strict_types=1);

namespace App\Modules\Mail\Data;

use App\Modules\Mail\Enums\RecipientStatus;
use Carbon\CarbonImmutable;

final readonly class RecipientData
{
    public function __construct(
        public int $id,
        public int $campaignId,
        public int $contactId,
        public string $email,
        public RecipientStatus $status,
        public int $attempts,
        public ?string $lastError,
        public ?string $messageId,
        public ?string $sesMessageId,
        public string $unsubscribeToken,
        public ?CarbonImmutable $sentAt,
        public ?CarbonImmutable $deliveredAt,
        public ?CarbonImmutable $bouncedAt,
        public ?CarbonImmutable $unsubscribedAt,
    ) {}
}
