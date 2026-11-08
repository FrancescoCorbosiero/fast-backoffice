<?php

declare(strict_types=1);

use App\Kernel\Reference;
use App\Modules\Audit\Queries\AuditTrail;
use App\Modules\Pipeline\Actions\ApproveChangeRequest;
use App\Modules\Pipeline\Actions\FailChangeRequest;
use App\Modules\Pipeline\Actions\LinkRepository;
use App\Modules\Pipeline\Actions\RejectChangeRequest;
use App\Modules\Pipeline\Actions\SubmitChangeRequest;
use App\Modules\Pipeline\Data\ChangeRequestInput;
use App\Modules\Pipeline\Enums\ChangeRequestKind;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Enums\DecidedBy;
use App\Modules\Pipeline\Enums\PipelineStatus;
use App\Modules\Pipeline\Events\ChangeRequestApproved;
use App\Modules\Pipeline\Events\ChangeRequestFailed;
use App\Modules\Pipeline\Events\ChangeRequestQueued;
use App\Modules\Pipeline\Events\ChangeRequestRejected;
use App\Modules\Pipeline\Events\ChangeRequestSubmitted;
use App\Modules\Pipeline\Jobs\CleanupRequest;
use App\Modules\Pipeline\Jobs\DeployStaticSiteRequest;
use App\Modules\Pipeline\Jobs\MergeOnboarding;
use App\Modules\Pipeline\Jobs\OnboardRepository;
use App\Modules\Pipeline\Jobs\RunStaticSiteRequest;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Models\WebsitePipeline;
use App\Modules\Pipeline\Queries\ChangeRequests;
use App\Modules\Pipeline\Queries\WebsitePipelines;
use App\Modules\Websites\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Pipeline\Site;

beforeEach(function (): void {
    Queue::fake();
    $this->travelTo('2026-03-15 10:00:00');
});

function submit(array $site, string $text = 'Cambia gli orari del sabato: chiudiamo alle 13.'): App\Modules\Pipeline\Data\ChangeRequestData
{
    return app(SubmitChangeRequest::class)->submit(new ChangeRequestInput($site['contact']->id, $site['website']->id, $text));
}

it('records a request, queues it and dispatches the run once the row is committed', function (): void {
    Event::fake([ChangeRequestSubmitted::class, ChangeRequestQueued::class]);
    $site = Site::make();

    $request = submit($site, '  Cambia gli orari del sabato.  ');

    expect($request->status)->toBe(ChangeRequestStatus::Queued)
        ->and($request->kind)->toBe(ChangeRequestKind::Change)
        ->and($request->requestText)->toBe('Cambia gli orari del sabato.')
        ->and($request->branch)->toBe('req/'.$request->id)
        ->and($request->previewSlug)->toMatch('/^[0-9a-z]{26}$/')
        ->and($request->clientName)->toBe('Pasticceria Bellavista S.r.l.')
        ->and($request->websiteName)->toBe($site['website']->name)
        ->and($request->previewUrl)->toBeNull();

    Queue::assertPushed(RunStaticSiteRequest::class, fn (RunStaticSiteRequest $job): bool => $job->changeRequestId === $request->id);
    Event::assertDispatched(ChangeRequestSubmitted::class, fn (ChangeRequestSubmitted $e): bool => $e->changeRequestId === $request->id && $e->contactId === $site['contact']->id);
    Event::assertDispatched(ChangeRequestQueued::class);

    $actions = array_map(fn ($entry) => $entry->action, app(AuditTrail::class)->forSubject(Reference::to('change_request', $request->id)));
    expect($actions)->toContain('pipeline.change_request.received', 'pipeline.change_request.queued');
});

it('refuses a request for a site that is not ready, not the client\'s, or with no text', function (): void {
    $site = Site::make(PipelineStatus::Onboarding);
    expect(fn () => submit($site))->toThrow(ValidationException::class, 'non riceve ancora');

    $ready = Site::make();
    $other = Site::make();
    expect(fn () => app(SubmitChangeRequest::class)->submit(new ChangeRequestInput($ready['contact']->id, $other['website']->id, 'Ciao')))->toThrow(ValidationException::class, 'siti del cliente');
    expect(fn () => submit($ready, '   '))->toThrow(ValidationException::class, 'cosa vuoi cambiare');
    expect(fn () => submit($ready, str_repeat('a', 4001)))->toThrow(ValidationException::class, 'troppo lunga');
    expect(fn () => app(SubmitChangeRequest::class)->submit(new ChangeRequestInput(999_999, $ready['website']->id, 'Ciao')))->toThrow(ValidationException::class, 'Scegli il cliente');

    Queue::assertNothingPushed();
});

it('approves a preview, records who decided, and hands the deploy to the worker', function (): void {
    Event::fake([ChangeRequestApproved::class]);
    $site = Site::make();
    $request = ChangeRequest::factory()->previewReady()->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);

    $approved = app(ApproveChangeRequest::class)->approve($request->id, DecidedBy::Client, $site['contact']->id);

    expect($approved->status)->toBe(ChangeRequestStatus::Approved)
        ->and($approved->decidedBy)->toBe(DecidedBy::Client)
        ->and($approved->decidedAt?->toDateTimeString())->toBe('2026-03-15 10:00:00');
    Queue::assertPushed(DeployStaticSiteRequest::class, fn (DeployStaticSiteRequest $job): bool => $job->changeRequestId === $request->id);
    Queue::assertNotPushed(MergeOnboarding::class);
    Event::assertDispatched(ChangeRequestApproved::class);

    $entries = app(AuditTrail::class)->forSubject(Reference::to('change_request', $request->id));
    $approval = collect($entries)->firstWhere('action', 'pipeline.change_request.approved');
    expect($approval)->not->toBeNull()
        ->and($approval->actorId)->toBeNull()
        ->and($approval->details)->toMatchArray(['decided_by' => 'client', 'contact_id' => $site['contact']->id]);
});

it('approving an onboarding dispatches the merge instead of a deploy', function (): void {
    $site = Site::make(PipelineStatus::Onboarding);
    $request = ChangeRequest::factory()->onboarding()->previewReady()->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);

    app(ApproveChangeRequest::class)->approve($request->id, DecidedBy::Owner);

    Queue::assertPushed(MergeOnboarding::class);
    Queue::assertNotPushed(DeployStaticSiteRequest::class);
});

it('rejects a preview with a reason and lets the worker clean up; a rejected onboarding leaves the pipeline unconfigured', function (): void {
    Event::fake([ChangeRequestRejected::class]);
    $site = Site::make(PipelineStatus::Onboarding);
    $request = ChangeRequest::factory()->onboarding()->previewReady()->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);

    $rejected = app(RejectChangeRequest::class)->reject($request->id, DecidedBy::Owner, '  Il build non è quello giusto  ');

    expect($rejected->status)->toBe(ChangeRequestStatus::Rejected)
        ->and($rejected->decisionReason)->toBe('Il build non è quello giusto')
        ->and($rejected->decidedBy)->toBe(DecidedBy::Owner)
        ->and(app(WebsitePipelines::class)->forWebsite($site['website']->id)?->status)->toBe(PipelineStatus::None);
    Queue::assertPushed(CleanupRequest::class);
    Event::assertDispatched(ChangeRequestRejected::class);
});

it('refuses decisions on a request that is not waiting for one, and pushes nothing', function (): void {
    $site = Site::make();
    foreach ([ChangeRequestStatus::Received, ChangeRequestStatus::Queued, ChangeRequestStatus::Running, ChangeRequestStatus::Approved, ChangeRequestStatus::Rejected, ChangeRequestStatus::Deployed, ChangeRequestStatus::Failed] as $status) {
        $request = ChangeRequest::factory()->status($status)->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);
        expect(fn () => app(ApproveChangeRequest::class)->approve($request->id, DecidedBy::Owner))->toThrow(LogicException::class, "can't become approved");
        expect(fn () => app(RejectChangeRequest::class)->reject($request->id, DecidedBy::Owner))->toThrow(LogicException::class, "can't become rejected");
        expect($request->refresh()->status)->toBe($status);
    }
    Queue::assertNothingPushed();
});

it('allows exactly the transitions of the lifecycle', function (): void {
    $allowed = [
        'received' => ['queued'],
        'queued' => ['running'],
        'running' => ['preview_ready', 'failed', 'needs_input'],
        'needs_input' => ['queued'],
        'preview_ready' => ['approved', 'rejected'],
        'approved' => ['deployed', 'failed'],
        'rejected' => [],
        'deployed' => [],
        'failed' => [],
    ];
    foreach (ChangeRequestStatus::cases() as $from) {
        foreach (ChangeRequestStatus::cases() as $to) {
            expect($from->canBecome($to))->toBe(in_array($to->value, $allowed[$from->value], true), "{$from->value} → {$to->value}");
        }
        expect($from->isTerminal())->toBe($allowed[$from->value] === []);
    }
});

it('marks a running or approved request failed with the reason, and leaves an ended request alone', function (): void {
    Event::fake([ChangeRequestFailed::class]);
    $site = Site::make();
    $running = ChangeRequest::factory()->status(ChangeRequestStatus::Running)->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);
    $rejected = ChangeRequest::factory()->status(ChangeRequestStatus::Rejected)->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);

    $failed = app(FailChangeRequest::class)->fail($running->id, 'La build è fallita.', 'build: errore');
    $untouched = app(FailChangeRequest::class)->fail($rejected->id, 'boom');

    expect($failed->status)->toBe(ChangeRequestStatus::Failed)
        ->and($failed->error)->toBe('La build è fallita.')
        ->and($failed->log)->toContain('build: errore')
        ->and($untouched->status)->toBe(ChangeRequestStatus::Rejected);
    Event::assertDispatched(ChangeRequestFailed::class, 1);
});

it('links a repository: the pipeline goes into onboarding and the audit run is queued', function (): void {
    $site = Site::make(PipelineStatus::None);
    $site['pipeline']->delete();

    $pipeline = app(LinkRepository::class)->link($site['website']->id, $site['repository']->id);

    expect($pipeline->status)->toBe(PipelineStatus::Onboarding)
        ->and($pipeline->repositoryId)->toBe($site['repository']->id);
    $request = ChangeRequest::query()->where('website_id', $site['website']->id)->sole();
    expect($request->kind)->toBe(ChangeRequestKind::Onboarding)
        ->and($request->status)->toBe(ChangeRequestStatus::Queued)
        ->and($request->branch)->toBe('pipeline/onboarding')
        ->and($request->contact_id)->toBe($site['contact']->id)
        ->and($request->request_text)->toContain($site['repository']->url);
    Queue::assertPushed(OnboardRepository::class, fn (OnboardRepository $job): bool => $job->changeRequestId === $request->id);
});

it('refuses to link while a request is open, a repository of another site, or an unknown website', function (): void {
    $site = Site::make();
    ChangeRequest::factory()->previewReady()->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);
    expect(fn () => app(LinkRepository::class)->link($site['website']->id, $site['repository']->id))->toThrow(LogicException::class, 'open request');

    $other = Site::make();
    expect(fn () => app(LinkRepository::class)->link($site['website']->id, $other['repository']->id))->toThrow(ValidationException::class, 'altro sito');
    expect(fn () => app(LinkRepository::class)->link(999_999, $site['repository']->id))->toThrow(ValidationException::class, 'Scegli il sito');
    expect(WebsitePipeline::query()->count())->toBe(2);
});

it('answers the dashboard counts in one query and lists a client\'s requests newest first', function (): void {
    $site = Site::make();
    $other = Site::make();
    ChangeRequest::factory()->previewReady()->count(2)->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);
    ChangeRequest::factory()->status(ChangeRequestStatus::Approved)->create(['contact_id' => $other['contact']->id, 'website_id' => $other['website']->id]);
    ChangeRequest::factory()->status(ChangeRequestStatus::Failed)->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id]);
    ChangeRequest::factory()->status(ChangeRequestStatus::Failed)->create(['contact_id' => $site['contact']->id, 'website_id' => $site['website']->id, 'updated_at' => CarbonImmutable::parse('2025-01-01')]);

    $queries = app(ChangeRequests::class);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $counts = $queries->counts(CarbonImmutable::parse('2026-02-15'));
    expect(count(DB::getQueryLog()))->toBe(1)
        ->and($counts)->toBe(['preview_ready' => 2, 'approved' => 1, 'failed' => 1]);

    $mine = $queries->forContact($site['contact']->id);
    expect($mine)->toHaveCount(4)
        ->and($mine[0]->id)->toBeGreaterThan($mine[1]->id)
        ->and(app(WebsitePipelines::class)->readyForContact($site['contact']->id))->toHaveCount(1);

    Website::factory()->create(['contact_id' => $site['contact']->id, 'name' => 'Senza pipeline', 'url' => 'https://altro.example', 'host' => 'altro.example']);
    expect(app(WebsitePipelines::class)->readyForContact($site['contact']->id))->toHaveCount(1);
});
