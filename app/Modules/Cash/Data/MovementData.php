<?php

declare(strict_types=1);

namespace App\Modules\Cash\Data;

use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Cash\Enums\Direction;
use Carbon\CarbonImmutable;

final readonly class MovementData
{
    public function __construct(
        public int $id,
        public int $accountId,
        public Direction $direction,
        public Money $amount,
        public CarbonImmutable $occurredOn,
        public string $description,
        public ?int $categoryId,
        public ?int $contactId,
        public ?Reference $allocation,
        public bool $isTaxable,
        public ?string $notes,
    ) {}

    public function signedAmount(): Money
    {
        return $this->amount->multipliedBy($this->direction->sign());
    }

    public function isAllocated(): bool
    {
        return $this->allocation !== null;
    }

    public function reference(): Reference
    {
        return Reference::to('cash_movement', $this->id);
    }
}
