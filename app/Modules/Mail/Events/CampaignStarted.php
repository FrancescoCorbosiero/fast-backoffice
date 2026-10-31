<?php

declare(strict_types=1);

namespace App\Modules\Mail\Events;

final readonly class CampaignStarted
{
    public function __construct(public int $campaignId, public int $recipients) {}
}
