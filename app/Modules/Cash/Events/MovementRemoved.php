<?php

declare(strict_types=1);

namespace App\Modules\Cash\Events;

final readonly class MovementRemoved
{
    public function __construct(
        public int $movementId,
        public ?string $allocation,
    ) {}
}
