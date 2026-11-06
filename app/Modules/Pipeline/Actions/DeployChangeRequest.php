<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Pipeline\Data\ChangeRequestData;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Events\ChangeRequestDeployed;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Queries\WebsitePipelines;
use App\Modules\Pipeline\Support\ManifestReader;
use App\Modules\Pipeline\Support\PathPolicy;
use App\Modules\Pipeline\Support\PipelineConfig;
use App\Modules\Pipeline\Support\PipelineRunner;
use App\Modules\Pipeline\Support\PreviewCopier;
use App\Modules\Pipeline\Support\WorkspaceBusyException;
use App\Modules\Repositories\Queries\Repositories;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Throwable;

/**
 * The body of DeployStaticSiteRequest: claim the approved request, gate
 * the branch once more, fast-forward the default branch, push it, run
 * the manifest's deploy command if there is one (else pushing is the
 * deploy), then drop the branch and the preview. The only place, with
 * MergeOnboarding, that pushes the default branch.
 */
final readonly class DeployChangeRequest
{
    public function __construct(
        private ConnectionInterface $db,
        private WebsitePipelines $pipelines,
        private Repositories $repositories,
        private PipelineRunner $runner,
        private ManifestReader $manifests,
        private PreviewCopier $previews,
        private FailChangeRequest $fail,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
        private PipelineConfig $config,
    ) {}

    public function deploy(int $changeRequestId): ChangeRequestData
    {
        $request = ChangeRequest::query()->findOrFail($changeRequestId);
        if (! $this->claim($request)) {
            return $request->toData();
        }

        try {
            return $this->runner->locked($request->website_id, fn (): ChangeRequestData => $this->execute($request->refresh()));
        } catch (WorkspaceBusyException $exception) {
            ChangeRequest::query()->whereKey($request->id)->update(['deploy_started_at' => null]);

            throw $exception;
        } catch (Throwable $exception) {
            return $this->fail->fail($request->id, $exception->getMessage(), clientError: 'Non siamo riusciti a pubblicare la modifica: lo studio controlla cosa è successo.');
        }
    }

    /**
     * Approved, and not being deployed by another worker: one UPDATE,
     * zero rows means someone else has it (or it isn't approved).
     */
    public static function claimStaleBefore(PipelineConfig $config): CarbonImmutable
    {
        return Date::now()->toImmutable()->subSeconds($config->workerTimeoutSeconds() + 300);
    }

    private function claim(ChangeRequest $request): bool
    {
        if ($request->toData()->isOnboarding()) {
            return false;
        }
        $stale = self::claimStaleBefore($this->config);

        return ChangeRequest::query()->whereKey($request->id)
            ->where('status', ChangeRequestStatus::Approved->value)
            ->where(fn ($query) => $query->whereNull('deploy_started_at')->orWhere('deploy_started_at', '<', $stale))
            ->update(['deploy_started_at' => Date::now()]) === 1;
    }

    private function execute(ChangeRequest $request): ChangeRequestData
    {
        $pipeline = $this->pipelines->forWebsite($request->website_id);
        if ($pipeline === null || ! $pipeline->isReady()) {
            throw new RuntimeException('La pipeline del sito non è pronta.');
        }
        $repository = $this->repositories->find($pipeline->repositoryId) ?? throw new RuntimeException('Il repository non esiste più.');
        $default = $repository->defaultBranch;

        $dir = $this->runner->workspace($request->website_id);
        $request->appendLog($this->runner->prepare($dir, $repository->url, $default));
        $this->runner->fetchBranch($dir, $request->branch);

        $manifestText = $this->runner->show($dir, 'origin/'.$default, ManifestReader::FILE) ?? throw new RuntimeException('pipeline.yml manca sul branch '.$default.'.');
        $manifest = $this->manifests->read($manifestText);

        $touched = $this->runner->touched($dir, 'origin/'.$default, 'origin/'.$request->branch);
        $refused = PathPolicy::forManifest($manifest)->refused($touched);
        if ($refused !== []) {
            throw new RuntimeException('Il branch tocca file fuori dai percorsi consentiti: '.implode(', ', $refused));
        }

        $merged = $this->runner->fastForward($dir, $default, $request->branch);
        $request->appendLog("\nUnito in {$default}: ".mb_substr($merged, -1000));

        if ($manifest->deploy !== null) {
            // The deploy command publishes a built site: build it on the merged
            // tree before anything is pushed, so a failed build pushes nothing.
            $build = $this->runner->shell($dir, $manifest->build, $this->config->buildTimeoutSeconds());
            $request->appendLog('$ '.$manifest->build."\n".mb_substr($build->output(), -4000));
            if (! $build->succeeded()) {
                throw new RuntimeException($build->timedOut ? 'La build ha superato il tempo massimo: niente è stato pubblicato.' : 'La build è fallita (exit '.$build->exitCode.'): niente è stato pubblicato.');
            }
        }

        $this->runner->pushDefault($dir, $default);
        $request->appendLog("Push di {$default} eseguito.");

        if ($manifest->deploy !== null) {
            $deploy = $this->runner->shell($dir, $manifest->deploy, $this->config->buildTimeoutSeconds(), withCredentials: true);
            $request->appendLog('$ '.$manifest->deploy."\n".mb_substr($deploy->output(), -4000));
            if (! $deploy->succeeded()) {
                throw new RuntimeException($deploy->timedOut ? 'Il deploy ha superato il tempo massimo.' : 'Il deploy è fallito (exit '.$deploy->exitCode.'): il branch è già unito, controlla l\'hosting.');
            }
        } else {
            $request->appendLog('Nessun comando di deploy in pipeline.yml: la pubblicazione avviene con il push.');
        }

        $data = $this->db->transaction(function () use ($request): ChangeRequestData {
            $request->refresh();
            $request->moveTo(ChangeRequestStatus::Deployed, ['error' => null]);
            $this->audit->record('pipeline.change_request.deployed', $request->toData()->reference(), ['branch' => $request->branch]);

            return $request->toData();
        });
        $this->events->dispatch(new ChangeRequestDeployed($data->id, $data->websiteId, $data->contactId));

        $this->runner->deleteRemoteBranch($dir, $request->branch);
        $this->previews->delete($this->runner->previewDir($request->preview_slug));

        return $data;
    }
}
