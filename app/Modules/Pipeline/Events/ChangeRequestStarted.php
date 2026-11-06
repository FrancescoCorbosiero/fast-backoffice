<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

/**
 * The worker picked the request up.
 */
final readonly class ChangeRequestStarted
{
    public function __construct(public int $changeRequestId, public int $websiteId, public int $contactId) {}
}
