<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Jobs;

use App\Modules\Pipeline\Actions\CleanupRequest as Cleanup;
use Throwable;

/**
 * After a rejection: drops the remote branch and the preview folder.
 */
final class CleanupRequest extends PipelineJob
{
    public function handle(Cleanup $cleanup): void
    {
        $this->attempt(fn () => $cleanup->cleanup($this->changeRequestId));
    }

    public function failed(?Throwable $exception): void
    {
        // The request is already rejected; a failed cleanup is reported, not recorded on it.
        if ($exception !== null) {
            report($exception);
        }
    }
}
