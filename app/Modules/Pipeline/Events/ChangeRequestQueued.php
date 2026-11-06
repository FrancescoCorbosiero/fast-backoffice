<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

/**
 * The request's job was dispatched.
 */
final readonly class ChangeRequestQueued
{
    public function __construct(public int $changeRequestId, public int $websiteId, public int $contactId) {}
}
