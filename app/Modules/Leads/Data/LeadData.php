<?php

declare(strict_types=1);

namespace App\Modules\Leads\Data;

use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Leads\Enums\LeadStatus;
use Carbon\CarbonImmutable;

final readonly class LeadData
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $company,
        public ?string $email,
        public ?string $phone,
        public ?string $source,
        public LeadStatus $status,
        public ?Money $estimatedValue,
        public ?CarbonImmutable $nextFollowUpOn,
        public ?string $notes,
        public ?int $contactId,
        public ?int $quoteId,
        public ?int $websiteId,
        public ?CarbonImmutable $convertedAt,
        public ?string $lostReason,
    ) {}

    public function reference(): Reference
    {
        return Reference::to('lead', $this->id);
    }

    public function title(): string
    {
        return $this->company !== null ? $this->name.' · '.$this->company : $this->name;
    }

    /**
     * Open, with a follow-up on or before today.
     */
    public function isFollowUpDue(CarbonImmutable $today): bool
    {
        return $this->status->isOpen() && $this->nextFollowUpOn !== null && $this->nextFollowUpOn->lessThanOrEqualTo($today->startOfDay());
    }
}
