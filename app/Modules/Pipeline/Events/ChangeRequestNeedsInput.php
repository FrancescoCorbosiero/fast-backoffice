<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

/**
 * The run changed nothing and asked the client something: the request
 * waits for their answer.
 */
final readonly class ChangeRequestNeedsInput
{
    public function __construct(public int $changeRequestId, public int $websiteId, public int $contactId) {}
}
