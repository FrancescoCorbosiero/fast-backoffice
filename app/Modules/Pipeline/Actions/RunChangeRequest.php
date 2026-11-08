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
use App\Modules\Pipeline\Support\PipelineConfig;
use App\Modules\Pipeline\Support\PipelineRunner;
use App\Modules\Pipeline\Support\PreviewCopier;
use App\Modules\Pipeline\Support\PromptBuilder;
use App\Modules\Pipeline\Support\RefusedRun;
use App\Modules\Pipeline\Support\WorkspaceBusyException;
use App\Modules\Pipeline\Support\WorkspaceUpload;
use App\Modules\Pipeline\Support\WorkspaceUploads;
use App\Modules\Repositories\Queries\Repositories;
use App\Modules\Websites\Queries\Websites;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

/**
 * The body of RunStaticSiteRequest: the run on req/<id> (with the
 * request's photos waiting in pipeline-uploads/), the path gate, the
 * manifest's build, the preview, the push. Anything that goes wrong ends
 * the request as failed, with the reason for the owner and the client.
 */
final readonly class RunChangeRequest
{
    public function __construct(
        private ConnectionInterface $db,
        private WebsitePipelines $pipelines,
        private Websites $websites,
        private Repositories $repositories,
        private PipelineRunner $runner,
        private PromptBuilder $prompts,
        private ManifestReader $manifests,
        private PreviewCopier $previews,
        private FailChangeRequest $fail,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
        private PipelineConfig $config,
    ) {}

    /**
     * @throws WorkspaceBusyException when another job holds the checkout: the job retries later
     */
    public function run(int $changeRequestId): ChangeRequestData
    {
        $request = ChangeRequest::query()->findOrFail($changeRequestId);
        if ($request->status !== ChangeRequestStatus::Queued) {
            return $request->toData();
        }
        if ($request->toData()->isOnboarding()) {
            return $this->fail->fail($request->id, 'Una richiesta di onboarding non si esegue come modifica.');
        }

        return $this->runner->locked($request->website_id, function () use ($request): ChangeRequestData {
            $request->refresh();
            if ($request->status !== ChangeRequestStatus::Queued) {
                return $request->toData();
            }
            $this->start($request);

            try {
                return $this->execute($request);
            } catch (Throwable $exception) {
                return $this->fail->fail($request->id, $exception->getMessage(), clientError: $exception instanceof RefusedRun ? $exception->clientMessage : null);
            }
        });
    }

    private function execute(ChangeRequest $request): ChangeRequestData
    {
        $pipeline = $this->pipelines->forWebsite($request->website_id);
        if ($pipeline === null || ! $pipeline->isReady()) {
            throw new RuntimeException('La pipeline del sito non è pronta: serve prima l\'onboarding del repository.');
        }
        $website = $this->websites->find($request->website_id) ?? throw new RuntimeException('Il sito non esiste più.');
        $repository = $this->repositories->find($pipeline->repositoryId) ?? throw new RuntimeException('Il repository non esiste più.');

        $dir = $this->runner->workspace($website->id);
        $request->appendLog($this->runner->prepare($dir, $repository->url, $repository->defaultBranch));

        $manifestText = $this->runner->show($dir, 'origin/'.$repository->defaultBranch, ManifestReader::FILE);
        if ($manifestText === null) {
            throw new RuntimeException('pipeline.yml manca sul branch '.$repository->defaultBranch.': ripeti l\'onboarding.');
        }
        $manifest = $this->manifests->read($manifestText);
        $systemPrompt = $this->runner->show($dir, 'origin/'.$repository->defaultBranch, 'CLAUDE.md');

        // The photos still kept, in the client's order, under the names the prompt lists.
        $data = $request->load('attachments')->toData();
        $uploads = [];
        foreach ($request->attachments as $attachment) {
            if ($attachment->removed_at === null) {
                $uploads[] = new WorkspaceUpload($attachment->toData()->workspaceName(), $attachment->stored_path, $attachment->sha256);
            }
        }

        $outcome = $this->runner->run($dir, $repository->defaultBranch, $request->branch, $this->prompts->change($data, $website, $manifest), PathPolicy::forManifest($manifest), $systemPrompt, $uploads);
        $request->appendLog($outcome->log);
        $request->forceFill(['cost_cents' => $outcome->costCents, 'touched_paths' => $outcome->touchedPaths])->save();

        if ($outcome->failed()) {
            throw new RefusedRun((string) $outcome->failure, 'Non siamo riusciti a completare la richiesta: lo studio la riguarderà.');
        }
        if ($outcome->wasRefused()) {
            $photos = array_filter($outcome->refusedPaths, fn (string $path): bool => str_starts_with($path, WorkspaceUploads::DIR.'/'));
            throw new RefusedRun(
                'La modifica tocca file fuori dai percorsi consentiti: '.implode(', ', $outcome->refusedPaths).($photos === [] ? '' : '. Le foto vanno spostate con git mv fuori da '.WorkspaceUploads::DIR.'/, non committate lì.'),
                'La modifica avrebbe toccato parti del sito che non si possono cambiare da qui: lo studio la riguarderà.',
            );
        }
        if (! $outcome->hasCommits) {
            $explanation = $outcome->resultText !== '' ? $outcome->resultText : 'Nessuna modifica: la richiesta non ha prodotto commit.';
            throw new RefusedRun($explanation, $explanation);
        }

        $build = $this->runner->shell($dir, $manifest->build, $this->config->buildTimeoutSeconds());
        $request->appendLog("\n$ ".$manifest->build."\n".mb_substr($build->output(), -4000));
        if (! $build->succeeded()) {
            throw new RefusedRun($build->timedOut ? 'La build ha superato il tempo massimo.' : 'La build è fallita (exit '.$build->exitCode.').', 'Non siamo riusciti a costruire l\'anteprima: lo studio controlla cosa è successo.');
        }

        $output = realpath($dir.'/'.$manifest->outputDir);
        $root = realpath($dir);
        if ($output === false || $root === false || ! str_starts_with($output, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('La cartella di output "'.$manifest->outputDir.'" non è dentro il repository.');
        }
        $files = $this->previews->copy($output, $this->runner->previewDir($request->preview_slug));
        $previewUrl = $this->runner->previewUrl($request->preview_slug);
        $request->appendLog("Anteprima: {$files} file in {$previewUrl}");

        $this->runner->push($dir, $request->branch, $repository->defaultBranch);

        $data = $this->db->transaction(function () use ($request, $previewUrl): ChangeRequestData {
            $request->refresh();
            $request->moveTo(ChangeRequestStatus::PreviewReady, ['preview_url' => $previewUrl, 'error' => null]);
            $this->audit->record('pipeline.change_request.preview_ready', $request->toData()->reference(), ['preview_url' => $previewUrl, 'touched_paths' => $request->touched_paths]);

            return $request->toData();
        });
        $this->events->dispatch(new PreviewReady($data->id, $data->websiteId, $data->contactId, $data->previewUrl));

        return $data;
    }

    private function start(ChangeRequest $request): void
    {
        $this->db->transaction(function () use ($request): void {
            $request->moveTo(ChangeRequestStatus::Running);
            $this->audit->record('pipeline.change_request.running', $request->toData()->reference());
        });
        $this->events->dispatch(new ChangeRequestStarted($request->id, $request->website_id, $request->contact_id));
    }
}
