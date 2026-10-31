<?php

declare(strict_types=1);

namespace App\Modules\Domains\Data;

use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Domains\Enums\DomainStatus;
use Carbon\CarbonImmutable;

final readonly class DomainData
{
    public function __construct(
        public int $id,
        public string $name,
        public int $contactId,
        public ?int $websiteId,
        public ?string $registrar,
        public DomainStatus $status,
        public ?CarbonImmutable $registeredOn,
        public CarbonImmutable $expiresOn,
        public int $renewalMonths,
        public bool $autoRenew,
        public ?Money $renewalCost,
        public ?int $accountId,
        public ?int $categoryId,
        public ?string $notes,
    ) {}

    public function reference(): Reference
    {
        return Reference::to('domain', $this->id);
    }

    /**
     * Past its expiry and still ours: the renewal was never logged (or the domain lapsed).
     */
    public function isExpired(CarbonImmutable $today): bool
    {
        return $this->status === DomainStatus::Active && $this->expiresOn->lessThan($today->startOfDay());
    }

    /**
     * Expires within this many days, today included as day 0.
     */
    public function expiresWithin(CarbonImmutable $today, int $days): bool
    {
        $today = $today->startOfDay();

        return $this->status === DomainStatus::Active
            && $this->expiresOn->greaterThanOrEqualTo($today)
            && $this->expiresOn->lessThanOrEqualTo($today->addDays($days));
    }

    public function hasPaidRenewal(): bool
    {
        return $this->renewalCost !== null && $this->renewalCost->isPositive();
    }
}
