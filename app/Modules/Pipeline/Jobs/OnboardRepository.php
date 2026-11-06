<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Jobs;

use App\Modules\Pipeline\Actions\RunOnboarding;

/**
 * Audits a newly linked repository: the CLI writes pipeline.yml and
 * CLAUDE.md on the onboarding branch for the owner to approve.
 */
final class OnboardRepository extends PipelineJob
{
    public function handle(RunOnboarding $run): void
    {
        $this->attempt(fn () => $run->run($this->changeRequestId));
    }
}
