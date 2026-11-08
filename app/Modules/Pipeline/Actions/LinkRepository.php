<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Pipeline\Data\WebsitePipelineData;
use App\Modules\Pipeline\Enums\ChangeRequestKind;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Enums\PipelineKind;
use App\Modules\Pipeline\Enums\PipelineStatus;
use App\Modules\Pipeline\Events\ChangeRequestQueued;
use App\Modules\Pipeline\Events\ChangeRequestSubmitted;
use App\Modules\Pipeline\Jobs\OnboardRepository;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Models\WebsitePipeline;
use App\Modules\Repositories\Queries\Repositories;
use App\Modules\Websites\Queries\Websites;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Gives a website a pipeline: the repository it is built from, audited by
 * an onboarding run the owner approves before the site takes requests.
 * Linking again (another repository, or a fresh audit) starts onboarding
 * over; a request still open on this website blocks it.
 */
final readonly class LinkRepository
{
    public const string ONBOARDING_BRANCH = 'pipeline/onboarding';

    public function __construct(
        private ConnectionInterface $db,
        private Websites $websites,
        private Repositories $repositories,
        private FindContact $contacts,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
        private Bus $bus,
    ) {}

    /**
     * @throws ValidationException
     * @throws LogicException when a request on this website is still open
     */
    public function link(int $websiteId, int $repositoryId, PipelineKind $kind = PipelineKind::StaticSite): WebsitePipelineData
    {
        $website = $this->websites->find($websiteId);
        if ($website === null) {
            throw ValidationException::withMessages(['website_id' => 'Scegli il sito.']);
        }

        $repository = $this->repositories->find($repositoryId);
        if ($repository === null) {
            throw ValidationException::withMessages(['repository_id' => 'Scegli il repository.']);
        }
        if ($repository->websiteId !== null && $repository->websiteId !== $website->id) {
            throw ValidationException::withMessages(['repository_id' => 'Questo repository è collegato a un altro sito.']);
        }

        $contact = $this->contacts->resolve($website->contactId);
        if ($contact === null) {
            throw ValidationException::withMessages(['website_id' => 'Il sito non ha un cliente.']);
        }

        [$pipeline, $request] = $this->db->transaction(function () use ($website, $repository, $kind, $contact): array {
            $open = ChangeRequest::query()->where('website_id', $website->id)->whereIn('status', [
                ChangeRequestStatus::Received->value, ChangeRequestStatus::Queued->value, ChangeRequestStatus::Running->value,
                ChangeRequestStatus::PreviewReady->value, ChangeRequestStatus::Approved->value, ChangeRequestStatus::NeedsInput->value,
            ])->lockForUpdate()->exists();
            if ($open) {
                throw new LogicException("Website {$website->id} has an open request: finish it before linking a repository.");
            }

            $pipeline = WebsitePipeline::query()->lockForUpdate()->firstOrNew(['website_id' => $website->id]);
            $pipeline->forceFill(['repository_id' => $repository->id, 'kind' => $kind, 'status' => PipelineStatus::Onboarding])->save();

            $request = ChangeRequest::query()->create([
                'contact_id' => $contact->id,
                'website_id' => $website->id,
                'client_name' => mb_substr($contact->displayName, 0, 160),
                'website_name' => mb_substr($website->name, 0, 120),
                'kind' => ChangeRequestKind::Onboarding,
                'status' => ChangeRequestStatus::Received,
                'request_text' => 'Onboarding del repository '.$repository->url.' (branch '.$repository->defaultBranch.').',
                'branch' => self::ONBOARDING_BRANCH,
                'preview_slug' => mb_strtolower((string) Str::ulid()),
            ]);
            $this->audit->record('pipeline.repository.linked', $pipeline->toData()->reference(), ['website_id' => $website->id, 'repository_id' => $repository->id, 'change_request_id' => $request->id]);

            $request->moveTo(ChangeRequestStatus::Queued);
            $this->audit->record('pipeline.change_request.queued', $request->toData()->reference());

            return [$pipeline->toData(), $request->toData()];
        });

        $this->events->dispatch(new ChangeRequestSubmitted($request->id, $request->websiteId, $request->contactId));
        $this->events->dispatch(new ChangeRequestQueued($request->id, $request->websiteId, $request->contactId));
        $this->bus->dispatch(new OnboardRepository($request->id));

        return $pipeline;
    }
}
