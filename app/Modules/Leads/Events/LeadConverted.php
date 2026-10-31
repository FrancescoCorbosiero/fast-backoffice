<?php

declare(strict_types=1);

namespace App\Modules\Leads\Events;

final readonly class LeadConverted
{
    public function __construct(
        public int $leadId,
        public int $contactId,
        public ?int $quoteId,
        public ?int $websiteId,
    ) {}
}
