<?php

declare(strict_types=1);

namespace App\Modules\Mail\Data;

use App\Kernel\Reference;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Mail\Enums\CampaignStatus;
use Carbon\CarbonImmutable;

final readonly class CampaignData
{
    /**
     * @param  list<ContactRole>  $audienceRoles
     * @param  array<string, int>  $counts  recipient status → how many
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $subject,
        public ?string $preheader,
        public string $bodyHtml,
        public array $audienceRoles,
        public CampaignStatus $status,
        public ?CarbonImmutable $scheduledAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
        public array $counts,
    ) {}

    public function reference(): Reference
    {
        return Reference::to('campaign', $this->id);
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    public function count(string ...$statuses): int
    {
        $sum = 0;
        foreach ($statuses as $status) {
            $sum += $this->counts[$status] ?? 0;
        }

        return $sum;
    }

    /**
     * Every recipient reached a terminal state: the campaign is done.
     */
    public function isComplete(): bool
    {
        return $this->total() > 0 && $this->count('queued') === 0;
    }
}
