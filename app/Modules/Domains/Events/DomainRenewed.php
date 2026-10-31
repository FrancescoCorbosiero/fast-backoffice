<?php

declare(strict_types=1);

namespace App\Modules\Domains\Events;

use Carbon\CarbonImmutable;

final readonly class DomainRenewed
{
    public function __construct(
        public int $domainId,
        public CarbonImmutable $renewedPeriodEndingOn,
        public CarbonImmutable $newExpiresOn,
        public int $movementId,
    ) {}
}
