<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

/**
 * Onboarding was approved and merged: the website takes change requests.
 */
final readonly class PipelineReady
{
    public function __construct(public int $websitePipelineId, public int $websiteId, public int $repositoryId) {}
}
