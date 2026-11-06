<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Pipeline\Data\ChangeRequestData;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Events\ChangeRequestStarted;
use App\Modules\Pipeline\Events\PreviewReady;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Queries\WebsitePipelines;
use App\Modules\Pipeline\Support\ManifestReader;
use App\Modules\Pipeline\Support\PathPolicy;
use App\Modules\Pipeline\Support\PipelineRunner;
use App\Modules\Pipeline\Support\PromptBuilder;
use App\Modules\Repositories\Queries\Repositories;
use App\Modules\Websites\Queries\Websites;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

/**
 * The body of OnboardRepository: the audit run on pipeline/onboarding,
 * which must leave exactly pipeline.yml (valid) and CLAUDE.md, pushed for
 * the owner to read and approve.
 */
final readonly class RunOnboarding
{
    public function __construct(
        private ConnectionInterface $db,
        private WebsitePipelines $pipelines,
        private Websites $websites,
        private Repositories $repositories,
        private PipelineRunner $runner,
        private PromptBuilder $prompts,
        private ManifestReader $manifests,
        private FailChangeRequest $fail,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    public function run(int $changeRequestId): ChangeRequestData
    {
        $request = ChangeRequest::query()->findOrFail($changeRequestId);
        if ($request->status !== ChangeRequestStatus::Queued) {
            return $request->toData();
        }
        if (! $request->toData()->isOnboarding()) {
            return $this->fail->fail($request->id, 'Questa non è una richiesta di onboarding.');
        }

        return $this->runner->locked($request->website_id, function () use ($request): ChangeRequestData {
            $request->refresh();
            if ($request->status !== ChangeRequestStatus::Queued) {
                return $request->toData();
            }
            $this->db->transaction(function () use ($request): void {
                $request->moveTo(ChangeRequestStatus::Running);
                $this->audit->record('pipeline.change_request.running', $request->toData()->reference());
            });
            $this->events->dispatch(new ChangeRequestStarted($request->id, $request->website_id, $request->contact_id));

            try {
                return $this->execute($request);
            } catch (Throwable $exception) {
                return $this->fail->fail($request->id, $exception->getMessage());
            }
        });
    }

    private function execute(ChangeRequest $request): ChangeRequestData
    {
        $pipeline = $this->pipelines->forWebsite($request->website_id) ?? throw new RuntimeException('Il sito non ha una pipeline.');
        $website = $this->websites->find($request->website_id) ?? throw new RuntimeException('Il sito non esiste più.');
        $repository = $this->repositories->find($pipeline->repositoryId) ?? throw new RuntimeException('Il repository non esiste più.');

        $dir = $this->runner->workspace($website->id);
        $request->appendLog($this->runner->prepare($dir, $repository->url, $repository->defaultBranch));

        $outcome = $this->runner->run($dir, $repository->defaultBranch, $request->branch, $this->prompts->onboarding($request->toData(), $website, $repository), PathPolicy::onboarding());
        $request->appendLog($outcome->log);
        $request->forceFill(['cost_cents' => $outcome->costCents, 'touched_paths' => $outcome->touchedPaths])->save();

        if ($outcome->failed()) {
            throw new RuntimeException((string) $outcome->failure);
        }
        if ($outcome->wasRefused()) {
            throw new RuntimeException('L\'onboarding ha toccato file che non doveva: '.implode(', ', $outcome->refusedPaths));
        }
        if (! $outcome->hasCommits) {
            throw new RuntimeException($outcome->resultText !== '' ? $outcome->resultText : 'L\'onboarding non ha prodotto commit.');
        }

        $files = [];
        foreach (PathPolicy::PROTECTED as $path) {
            $content = $this->runner->show($dir, $request->branch, $path);
            if ($content === null || trim($content) === '') {
                throw new RuntimeException("L'onboarding non ha scritto {$path}.");
            }
            $files[$path] = $content;
        }
        $manifest = $this->manifests->read($files[ManifestReader::FILE]);
        $request->appendLog("\npipeline.yml: build \"{$manifest->build}\", output \"{$manifest->outputDir}\", percorsi modificabili: ".implode(', ', $manifest->editablePaths).($manifest->deploy === null ? '' : ", deploy \"{$manifest->deploy}\"."));

        $this->runner->push($dir, $request->branch, $repository->defaultBranch);

        $data = $this->db->transaction(function () use ($request, $files): ChangeRequestData {
            $request->refresh();
            $request->moveTo(ChangeRequestStatus::PreviewReady, ['generated_files' => $files, 'error' => null]);
            $this->audit->record('pipeline.change_request.preview_ready', $request->toData()->reference(), ['files' => array_keys($files)]);

            return $request->toData();
        });
        $this->events->dispatch(new PreviewReady($data->id, $data->websiteId, $data->contactId, null));

        return $data;
    }
}
