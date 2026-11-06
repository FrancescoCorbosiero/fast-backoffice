<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Pipeline\Data\ChangeRequestData;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Queries\WebsitePipelines;
use App\Modules\Pipeline\Support\PipelineRunner;
use App\Modules\Pipeline\Support\PreviewCopier;
use App\Modules\Repositories\Queries\Repositories;

/**
 * The body of the CleanupRequest job: after a rejection, the remote
 * branch and the preview folder go. Tolerant: a branch already gone is
 * fine.
 */
final readonly class CleanupRequest
{
    public function __construct(
        private WebsitePipelines $pipelines,
        private Repositories $repositories,
        private PipelineRunner $runner,
        private PreviewCopier $previews,
    ) {}

    public function cleanup(int $changeRequestId): ChangeRequestData
    {
        $request = ChangeRequest::query()->findOrFail($changeRequestId);
        if ($request->status !== ChangeRequestStatus::Rejected) {
            return $request->toData();
        }

        $this->previews->delete($this->runner->previewDir($request->preview_slug));
        $request->appendLog('Anteprima rimossa.');

        $pipeline = $this->pipelines->forWebsite($request->website_id);
        $repository = $pipeline === null ? null : $this->repositories->find($pipeline->repositoryId);
        if ($repository !== null) {
            $dir = $this->runner->workspace($request->website_id);
            $this->runner->locked($request->website_id, function () use ($dir, $repository, $request): void {
                $this->runner->prepare($dir, $repository->url, $repository->defaultBranch);
                $request->appendLog($this->runner->deleteRemoteBranch($dir, $request->branch) ? "Branch {$request->branch} eliminato." : "Branch {$request->branch} già assente.");
            });
        }

        return $request->refresh()->toData();
    }
}
