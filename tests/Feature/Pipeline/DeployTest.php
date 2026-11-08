<?php

declare(strict_types=1);

use App\Modules\Pipeline\Actions\CleanupRequest as Cleanup;
use App\Modules\Pipeline\Actions\DeployChangeRequest;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Events\ChangeRequestDeployed;
use App\Modules\Pipeline\Jobs\CleanupRequest;
use App\Modules\Pipeline\Jobs\DeployApprovedRequests;
use App\Modules\Pipeline\Jobs\DeployStaticSiteRequest;
use App\Modules\Pipeline\Jobs\MergeOnboarding;
use App\Modules\Pipeline\Models\ChangeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Pipeline\Site;
use Tests\Support\FakeCommandRunner;

beforeEach(function (): void {
    $this->fake = Site::fake();
    $this->site = Site::make();
    $this->request = ChangeRequest::factory()->previewReady()->status(ChangeRequestStatus::Approved)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id, 'branch' => 'req/42']);
    mkdir(Site::previewDir($this->request->preview_slug), 0755, true);
    file_put_contents(Site::previewDir($this->request->preview_slug).'/index.html', 'anteprima');
});

function deploy(ChangeRequest $request): ChangeRequest
{
    (new DeployStaticSiteRequest($request->id))->handle(app(DeployChangeRequest::class));

    return $request->refresh();
}

it('fast-forwards the default branch, pushes it, runs the deploy command, and drops the branch and the preview', function (): void {
    Event::fake([ChangeRequestDeployed::class]);
    $this->fake->files['pipeline.yml'] = FakeCommandRunner::manifest(deploy: 'rsync -a dist/ server:/var/www/site/');

    $request = deploy($this->request);

    expect($request->status)->toBe(ChangeRequestStatus::Deployed)
        ->and($request->log)->toContain('Unito in main')->toContain('Push di main eseguito')->toContain('$ rsync -a dist/ server:/var/www/site/')
        ->and($this->fake->calls('git fetch -- origin req/42'))->toHaveCount(1)
        ->and($this->fake->calls('git merge --ff-only -- origin/req/42'))->toHaveCount(1)
        ->and($this->fake->calls('git push -- origin main:main'))->toHaveCount(1)
        ->and($this->fake->calls('git push --delete -- origin req/42'))->toHaveCount(1)
        ->and($this->fake->calls('sh -c'))->toHaveCount(2)
        ->and($this->fake->calls('sh -c')[0]['argv'])->toBe(['sh', '-c', 'npm ci && npm run build'])
        ->and($this->fake->calls('sh -c')[1]['argv'])->toBe(['sh', '-c', 'rsync -a dist/ server:/var/www/site/'])
        ->and($this->fake->calls('sh -c')[0]['env'])->not->toHaveKey('GIT_SSH_COMMAND')
        ->and($this->fake->calls('sh -c')[1]['env'])->toHaveKey('GIT_SSH_COMMAND')
        ->and($this->fake->calls('git push -- origin main:main')[0]['env'])->toHaveKey('GIT_SSH_COMMAND')
        ->and(is_dir(Site::previewDir($request->preview_slug)))->toBeFalse();
    // Build before push: the order of the calls.
    $joined = array_map(fn (array $call): string => implode(' ', array_slice($call['argv'], 0, 3)), $this->fake->calls);
    expect(array_search('sh -c npm ci && npm run build', $joined, true))->toBeLessThan(array_search('git push --', $joined, true));
    Event::assertDispatched(ChangeRequestDeployed::class);
});

it('treats the push as the deploy when the manifest has no deploy command', function (): void {
    $request = deploy($this->request);

    expect($request->status)->toBe(ChangeRequestStatus::Deployed)
        ->and($request->log)->toContain('Nessun comando di deploy')
        ->and($this->fake->ran('sh -c'))->toBeFalse();
});

it('fails without pushing when the branch is not fast-forward or touches files outside the policy', function (): void {
    $this->fake->on('git merge', FakeCommandRunner::fail('fatal: Not possible to fast-forward, aborting.', 128));
    $request = deploy($this->request);
    expect($request->status)->toBe(ChangeRequestStatus::Failed)
        ->and($request->error)->toContain('fast-forward')
        ->and($this->fake->ran('git push'))->toBeFalse();

    $again = ChangeRequest::factory()->previewReady()->status(ChangeRequestStatus::Approved)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id, 'branch' => 'req/43']);
    $this->fake->touched = ['content/x.md', 'pipeline.yml'];
    $result = deploy($again);
    expect($result->status)->toBe(ChangeRequestStatus::Failed)
        ->and($result->error)->toContain('pipeline.yml')
        ->and($this->fake->ran('git merge'))->toBeTrue()
        ->and($this->fake->calls('git merge'))->toHaveCount(1);
});

it('gates the branch on git\'s unquoted listing, so a path with an accent deploys', function (): void {
    $this->fake->touched = ['content/caffè.md'];

    $request = deploy($this->request);

    expect($request->status)->toBe(ChangeRequestStatus::Deployed)
        ->and($this->fake->calls('git diff --name-only')[0]['argv'])->toContain('-z');
});

it('gates the branch again on deploy: a photo committed under pipeline-uploads/ is refused', function (): void {
    $this->fake->touched = ['content/chi-sono.md', 'pipeline-uploads/1-ritratto.jpg'];

    $request = deploy($this->request);

    expect($request->status)->toBe(ChangeRequestStatus::Failed)
        ->and($request->error)->toContain('pipeline-uploads/1-ritratto.jpg (cartella degli allegati)')
        ->and($this->fake->ran('git push -- origin main:main'))->toBeFalse();
});

it('marks the request failed when the deploy command fails, after the merge was pushed', function (): void {
    $this->fake->files['pipeline.yml'] = FakeCommandRunner::manifest(deploy: './deploy.sh');
    $this->fake->on('sh -c ./deploy.sh', FakeCommandRunner::fail('ssh: connection refused', 255));

    $request = deploy($this->request);

    expect($request->status)->toBe(ChangeRequestStatus::Failed)
        ->and($request->error)->toContain('deploy è fallito (exit 255)')->toContain('già unito')
        ->and($request->client_error)->toContain('Non siamo riusciti a pubblicare')
        ->and($this->fake->calls('git push -- origin main:main'))->toHaveCount(1);
});

it('pushes nothing when the build before a deploy command fails', function (): void {
    $this->fake->files['pipeline.yml'] = FakeCommandRunner::manifest(deploy: './deploy.sh');
    $this->fake->on('sh -c npm ci', FakeCommandRunner::fail('npm ERR!', 1));

    $request = deploy($this->request);

    expect($request->status)->toBe(ChangeRequestStatus::Failed)
        ->and($request->error)->toContain('build è fallita')->toContain('niente è stato pubblicato')
        ->and($this->fake->ran('git push'))->toBeFalse()
        ->and($this->fake->ran('sh -c ./deploy.sh'))->toBeFalse();
});

it('sends the job back to the queue when another job holds the checkout, releasing the claim', function (): void {
    $store = cache()->store()->getStore();
    expect($store)->toBeInstanceOf(Illuminate\Contracts\Cache\LockProvider::class);
    $lock = $store->lock('pipeline:website:'.$this->site['website']->id, 600);
    expect($lock->get())->toBeTrue();

    expect(fn () => app(DeployChangeRequest::class)->deploy($this->request->id))->toThrow(App\Modules\Pipeline\Support\WorkspaceBusyException::class);
    expect($this->request->refresh()->status)->toBe(ChangeRequestStatus::Approved)
        ->and($this->request->deploy_started_at)->toBeNull()
        ->and($this->fake->calls)->toBe([]);

    $queued = ChangeRequest::factory()->status(ChangeRequestStatus::Queued)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id, 'branch' => 'req/70']);
    expect(fn () => app(App\Modules\Pipeline\Actions\RunChangeRequest::class)->run($queued->id))->toThrow(App\Modules\Pipeline\Support\WorkspaceBusyException::class);
    expect($queued->refresh()->status)->toBe(ChangeRequestStatus::Queued);

    $lock->release();
    expect(deploy($this->request)->status)->toBe(ChangeRequestStatus::Deployed);
});

it('claims the deploy once: a second worker finds nothing to do, and the recovery job re-dispatches only stale claims', function (): void {
    $this->travelTo('2026-03-15 10:00:00');
    Queue::fake();

    $first = deploy($this->request);
    expect($first->status)->toBe(ChangeRequestStatus::Deployed);

    $stuck = ChangeRequest::factory()->status(ChangeRequestStatus::Approved)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id, 'branch' => 'req/50', 'deploy_started_at' => CarbonImmutable::parse('2026-03-15 09:59:00')]);
    $calls = count($this->fake->calls);
    deploy($stuck);
    expect($stuck->refresh()->status)->toBe(ChangeRequestStatus::Approved)
        ->and(count($this->fake->calls))->toBe($calls);

    $lost = ChangeRequest::factory()->status(ChangeRequestStatus::Approved)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id, 'branch' => 'req/51']);
    $onboarding = ChangeRequest::factory()->onboarding()->status(ChangeRequestStatus::Approved)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id]);
    $old = ChangeRequest::factory()->status(ChangeRequestStatus::Approved)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id, 'branch' => 'req/52', 'deploy_started_at' => CarbonImmutable::parse('2026-03-15 07:00:00')]);

    $job = app(DeployApprovedRequests::class);
    $result = $job->run();

    expect($job->key())->toBe('pipeline.deploy-approved')
        ->and($result->succeeded())->toBeTrue()
        ->and($result->output)->toContain('3 richieste');
    Queue::assertPushed(DeployStaticSiteRequest::class, fn (DeployStaticSiteRequest $j): bool => in_array($j->changeRequestId, [$lost->id, $old->id], true));
    Queue::assertPushed(DeployStaticSiteRequest::class, 2);
    Queue::assertPushed(MergeOnboarding::class, fn (MergeOnboarding $j): bool => $j->changeRequestId === $onboarding->id);
    Queue::assertNotPushed(DeployStaticSiteRequest::class, fn (DeployStaticSiteRequest $j): bool => $j->changeRequestId === $stuck->id);
});

it('cleans up a rejected request: preview gone, remote branch deleted, and tolerates a branch already gone', function (): void {
    $this->request->update(['status' => ChangeRequestStatus::Rejected]);

    (new CleanupRequest($this->request->id))->handle(app(Cleanup::class));

    expect(is_dir(Site::previewDir($this->request->preview_slug)))->toBeFalse()
        ->and($this->fake->calls('git push --delete -- origin req/42'))->toHaveCount(1)
        ->and($this->fake->calls('git push --delete -- origin req/42')[0]['argv'])->toBe(['git', 'push', '--delete', '--', 'origin', 'req/42'])
        ->and($this->request->refresh()->log)->toContain('Branch req/42 eliminato');

    $gone = ChangeRequest::factory()->status(ChangeRequestStatus::Rejected)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id, 'branch' => 'req/60']);
    $this->fake->on('git push --delete -- origin req/60', FakeCommandRunner::fail('remote ref does not exist'));
    (new CleanupRequest($gone->id))->handle(app(Cleanup::class));
    expect($gone->refresh()->log)->toContain('già assente');

    $approved = ChangeRequest::factory()->status(ChangeRequestStatus::Approved)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id, 'branch' => 'req/61']);
    (new CleanupRequest($approved->id))->handle(app(Cleanup::class));
    expect($this->fake->ran('git push --delete -- origin req/61'))->toBeFalse();
});

it('configures every pipeline job for the pipeline queue, one exception, and the worker timeout', function (): void {
    config()->set('pipeline.queue.connection', 'redis-pipeline');
    config()->set('pipeline.queue.name', 'pipeline');
    config()->set('pipeline.timeout_seconds', 1000);
    config()->set('pipeline.build_timeout_seconds', 200);

    $job = new DeployStaticSiteRequest(7);

    expect($job->connection)->toBe('redis-pipeline')
        ->and($job->queue)->toBe('pipeline')
        ->and($job->maxExceptions)->toBe(1)
        ->and($job->tries)->toBeGreaterThan(1)
        ->and($job->timeout)->toBe(1500);
});
