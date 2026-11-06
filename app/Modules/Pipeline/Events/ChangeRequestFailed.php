<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

/**
 * The run, the build or the deploy failed; the error says why.
 */
final readonly class ChangeRequestFailed
{
    public function __construct(public int $changeRequestId, public int $websiteId, public int $contactId) {}
}
