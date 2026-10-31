<?php

declare(strict_types=1);

namespace App\Modules\Leads\Data;

final readonly class ConversionResult
{
    public function __construct(
        public LeadData $lead,
        public int $contactId,
        public bool $contactCreated,
        public ?int $quoteId,
        public ?int $websiteId,
    ) {}
}
