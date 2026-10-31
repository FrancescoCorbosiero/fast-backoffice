<?php

declare(strict_types=1);

namespace App\Modules\Audit\Data;

use Carbon\CarbonImmutable;

final readonly class AuditEntryData
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public int $id,
        public ?int $actorId,
        public string $action,
        public ?string $subject,
        public array $details,
        public CarbonImmutable $createdAt,
    ) {}
}
