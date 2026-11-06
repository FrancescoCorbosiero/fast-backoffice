<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

/**
 * A client (or the owner for them) submitted a request.
 */
final readonly class ChangeRequestSubmitted
{
    public function __construct(public int $changeRequestId, public int $websiteId, public int $contactId) {}
}
