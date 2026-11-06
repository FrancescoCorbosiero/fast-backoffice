<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

/**
 * The request's commits are on the default branch and deployed.
 */
final readonly class ChangeRequestDeployed
{
    public function __construct(public int $changeRequestId, public int $websiteId, public int $contactId) {}
}
