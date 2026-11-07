<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Pipeline\Data\ChangeRequestData;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Enums\PipelineStatus;
use App\Modules\Pipeline\Events\ChangeRequestDeployed;
use App\Modules\Pipeline\Events\PipelineReady;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Models\WebsitePipeline;
use App\Modules\Pipeline\Support\ManifestReader;
use App\Modules\Pipeline\Support\PathPolicy;
use App\Modules\Pipeline\Support\PipelineConfig;
use App\Modules\Pipeline\Support\PipelineRunner;
use App\Modules\Pipeline\Support\WorkspaceBusyException;
use App\Modules\Repositories\Queries\Repositories;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Throwable;

/**
 * The body of the MergeOnboarding job: the approved onboarding branch
 * (pipeline.yml and CLAUDE.md, nothing else) fast-forwarded onto the
 * default branch and pushed; the website becomes ready. When the branch
 * has no commits beyond the default branch (the manifest was already
 * there), nothing is merged or pushed: the website just becomes ready.
 */
final readonly class MergeOnboarding
{
    public function __construct(
        private ConnectionInterface $db,
        private Repositories $repositories,
        private PipelineRunner $runner,
        private ManifestReader $manifests,
        private FailChangeRequest $fail,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
        private PipelineConfig $config,
    ) {}

    public function merge(int $changeRequestId): ChangeRequestData
    {
        $request = ChangeRequest::query()->findOrFail($changeRequestId);
        if (! $request->toData()->isOnboarding()) {
            return $request->toData();
        }
        $stale = DeployChangeRequest::claimStaleBefore($this->config);
        $claimed = ChangeRequest::query()->whereKey($request->id)
            ->where('status', ChangeRequestStatus::Approved->value)
            ->where(fn ($query) => $query->whereNull('deploy_started_at')->orWhere('deploy_started_at', '<', $stale))
            ->update(['deploy_started_at' => Date::now()]) === 1;
        if (! $claimed) {
            return $request->toData();
        }

        try {
            return $this->runner->locked($request->website_id, fn (): ChangeRequestData => $this->execute($request->refresh()));
        } catch (WorkspaceBusyException $exception) {
            ChangeRequest::query()->whereKey($request->id)->update(['deploy_started_at' => null]);

            throw $exception;
        } catch (Throwable $exception) {
            return $this->fail->fail($request->id, $exception->getMessage());
        }
    }

    private function execute(ChangeRequest $request): ChangeRequestData
    {
        $pipeline = WebsitePipeline::query()->where('website_id', $request->website_id)->first() ?? throw new RuntimeException('Il sito non ha una pipeline.');
        $repository = $this->repositories->find($pipeline->repository_id) ?? throw new RuntimeException('Il repository non esiste più.');
        $default = $repository->defaultBranch;

        $dir = $this->runner->workspace($request->website_id);
        $request->appendLog($this->runner->prepare($dir, $repository->url, $default));
        // An onboarding that found a valid pipeline.yml already on the default
        // branch pushed no branch: there is nothing to merge, only to approve.
        $published = $this->runner->remoteBranchExists($dir, $request->branch);
        $ref = 'origin/'.$default;
        if ($published) {
            $this->runner->fetchBranch($dir, $request->branch);
            $ref = 'origin/'.$request->branch;
        }

        $touched = $this->runner->touched($dir, 'origin/'.$default, $ref);
        $refused = PathPolicy::onboarding()->refused($touched);
        if ($refused !== []) {
            throw new RuntimeException('Il branch di onboarding tocca altri file: '.implode(', ', $refused));
        }
        $manifestText = $this->runner->show($dir, $ref, ManifestReader::FILE) ?? throw new RuntimeException($published ? 'pipeline.yml manca sul branch di onboarding.' : "pipeline.yml manca su {$default}.");
        $this->manifests->read($manifestText);

        if ($this->runner->commitsAhead($dir, 'origin/'.$default, $ref) > 0) {
            $merged = $this->runner->fastForward($dir, $default, $request->branch);
            $request->appendLog("\nUnito in {$default}: ".mb_substr($merged, -1000));
            $this->runner->pushDefault($dir, $default);
            $request->appendLog("Push di {$default} eseguito: il sito accetta richieste di modifica.");
        } else {
            $request->appendLog("\nNiente da unire: pipeline.yml è già su {$default}. Il sito accetta richieste di modifica.");
        }

        $data = $this->db->transaction(function () use ($request, $pipeline): ChangeRequestData {
            $request->refresh();
            $request->moveTo(ChangeRequestStatus::Deployed, ['error' => null]);
            $pipeline->refresh();
            $pipeline->forceFill(['status' => PipelineStatus::Ready])->save();
            $this->audit->record('pipeline.change_request.deployed', $request->toData()->reference(), ['branch' => $request->branch]);
            $this->audit->record('pipeline.pipeline.ready', $pipeline->toData()->reference(), ['website_id' => $pipeline->website_id]);

            return $request->toData();
        });
        $this->events->dispatch(new ChangeRequestDeployed($data->id, $data->websiteId, $data->contactId));
        $this->events->dispatch(new PipelineReady($pipeline->id, $pipeline->website_id, $pipeline->repository_id));

        if ($published) {
            $this->runner->deleteRemoteBranch($dir, $request->branch);
        }

        return $data;
    }
}
