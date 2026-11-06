<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

/**
 * The owner or the client approved the preview.
 */
final readonly class ChangeRequestApproved
{
    public function __construct(public int $changeRequestId, public int $websiteId, public int $contactId) {}
}
