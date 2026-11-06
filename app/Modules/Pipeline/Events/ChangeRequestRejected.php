<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

/**
 * The owner or the client rejected the preview.
 */
final readonly class ChangeRequestRejected
{
    public function __construct(public int $changeRequestId, public int $websiteId, public int $contactId) {}
}
