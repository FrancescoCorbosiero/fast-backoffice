<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Jobs;

use App\Modules\Pipeline\Actions\MergeOnboarding as Merge;

/**
 * Merges an approved onboarding branch: the manifest lands on the default
 * branch and the website becomes ready.
 */
final class MergeOnboarding extends PipelineJob
{
    public function handle(Merge $merge): void
    {
        $this->attempt(fn () => $merge->merge($this->changeRequestId));
    }
}
