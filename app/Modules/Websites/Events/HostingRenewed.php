<?php

declare(strict_types=1);

namespace App\Modules\Websites\Events;

final readonly class HostingRenewed
{
    public function __construct(public int $websiteId, public \Carbon\CarbonImmutable $renewedOn, public int $movementId) {}
}
