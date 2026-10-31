<?php

declare(strict_types=1);

namespace App\Modules\Websites\Data;

use App\Kernel\Dates\Frequency;
use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Websites\Enums\WebsiteStatus;
use Carbon\CarbonImmutable;

final readonly class WebsiteData
{
    public function __construct(
        public int $id,
        public int $contactId,
        public string $name,
        public string $url,
        public string $host,
        public WebsiteStatus $status,
        public ?string $hostingProvider,
        public ?CarbonImmutable $hostingStartedOn,
        public ?CarbonImmutable $hostingRenewsOn,
        public ?Frequency $hostingFrequency,
        public ?Money $hostingCost,
        public ?int $hostingAccountId,
        public ?int $hostingCategoryId,
        public ?int $subscriptionId,
        public bool $uptimeEnabled,
        public ?CarbonImmutable $lastCheckedAt,
        public ?bool $isUp,
        public ?int $lastStatusCode,
        public ?int $lastResponseMs,
        public int $consecutiveFailures,
        public ?CarbonImmutable $downSince,
        public ?string $notes,
    ) {}

    public function reference(): Reference
    {
        return Reference::to('website', $this->id);
    }

    /**
     * The hosting is paid by the studio on a cadence: there is something to renew.
     */
    public function hasHostingRenewal(): bool
    {
        return $this->hostingRenewsOn !== null && $this->hostingFrequency !== null && $this->hostingCost !== null && $this->hostingCost->isPositive();
    }

    public function hostingRenewalOverdue(CarbonImmutable $today): bool
    {
        return $this->hasHostingRenewal() && $this->status === WebsiteStatus::Active && $this->hostingRenewsOn !== null && $this->hostingRenewsOn->lessThan($today->startOfDay());
    }

    public function isMonitored(): bool
    {
        return $this->uptimeEnabled && $this->status === WebsiteStatus::Active;
    }
}
