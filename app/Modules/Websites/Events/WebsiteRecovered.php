<?php

declare(strict_types=1);

namespace App\Modules\Websites\Events;

final readonly class WebsiteRecovered
{
    public function __construct(public int $websiteId, public ?\Carbon\CarbonImmutable $downSince) {}
}
