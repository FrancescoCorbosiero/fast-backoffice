<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Jobs;

use App\Modules\Pipeline\Actions\DeployChangeRequest;

/**
 * Merges an approved request into the default branch, pushes and deploys.
 */
final class DeployStaticSiteRequest extends PipelineJob
{
    public function handle(DeployChangeRequest $deploy): void
    {
        $this->attempt(fn () => $deploy->deploy($this->changeRequestId));
    }
}
