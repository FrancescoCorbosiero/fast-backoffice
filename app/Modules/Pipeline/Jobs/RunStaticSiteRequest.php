<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Jobs;

use App\Modules\Pipeline\Actions\RunChangeRequest;

/**
 * Runs a client's change request on the site's repository and builds the
 * preview.
 */
final class RunStaticSiteRequest extends PipelineJob
{
    public function handle(RunChangeRequest $run): void
    {
        $this->attempt(fn () => $run->run($this->changeRequestId));
    }
}
