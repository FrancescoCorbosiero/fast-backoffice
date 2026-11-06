<?php

declare(strict_types=1);

use App\Modules\Pipeline\Actions\MergeOnboarding as Merge;
use App\Modules\Pipeline\Actions\RunOnboarding;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Enums\PipelineStatus;
use App\Modules\Pipeline\Events\PipelineReady;
use App\Modules\Pipeline\Jobs\MergeOnboarding;
use App\Modules\Pipeline\Jobs\OnboardRepository;
use App\Modules\Pipeline\Models\ChangeRequest;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Pipeline\Site;
use Tests\Support\FakeCommandRunner;

beforeEach(function (): void {
    $this->fake = Site::fake();
    $this->fake->touched = ['pipeline.yml', 'CLAUDE.md'];
    $this->fake->claudeResult['result'] = 'Sito Hugo, contenuti in content/, layout in layouts/.';
    $this->site = Site::make(PipelineStatus::Onboarding);
    $this->request = ChangeRequest::factory()->onboarding()->status(ChangeRequestStatus::Queued)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id]);
});

function onboard(ChangeRequest $request): ChangeRequest
{
    (new OnboardRepository($request->id))->handle(app(RunOnboarding::class));

    return $request->refresh();
}

it('audits the repository on the onboarding branch and shows the two generated files', function (): void {
    $request = onboard($this->request);

    expect($request->status)->toBe(ChangeRequestStatus::PreviewReady)
        ->and($request->generated_files)->toHaveKeys(['pipeline.yml', 'CLAUDE.md'])
        ->and($request->generated_files['pipeline.yml'])->toContain('output_dir: dist')
        ->and($request->preview_url)->toBeNull()
        ->and($request->log)->toContain('Sito Hugo')->toContain('percorsi modificabili: content, layouts')
        ->and($this->fake->calls('git push')[0]['argv'])->toContain('pipeline/onboarding:pipeline/onboarding')
        ->and($this->fake->ran('sh -c'))->toBeFalse();

    $prompt = $this->fake->calls('claude')[0]['argv'][2];
    expect($prompt)->toContain('pipeline.yml')->toContain('CLAUDE.md')->toContain('editable_paths')->toContain($this->site['website']->host)
        ->and(implode(' ', $this->fake->calls('claude')[0]['argv']))->not->toContain('--append-system-prompt-file');
});

it('fails when the run leaves no commits, writes other files, misses a file, or writes an invalid manifest', function (): void {
    $this->fake->commitsAhead = 0;
    expect(onboard($this->request)->error)->toBe('Sito Hugo, contenuti in content/, layout in layouts/.');

    $this->fake->commitsAhead = 1;
    $other = ChangeRequest::factory()->onboarding()->status(ChangeRequestStatus::Queued)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id]);
    $this->fake->touched = ['pipeline.yml', 'CLAUDE.md', 'content/home.md'];
    expect(onboard($other)->error)->toContain('content/home.md');

    $this->fake->touched = ['pipeline.yml'];
    unset($this->fake->files['CLAUDE.md']);
    $missing = ChangeRequest::factory()->onboarding()->status(ChangeRequestStatus::Queued)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id]);
    expect(onboard($missing)->error)->toContain('non ha scritto CLAUDE.md');

    $this->fake->files['CLAUDE.md'] = '# ok';
    $this->fake->files['pipeline.yml'] = "build: npm run build\noutput_dir: dist\neditable_paths:\n  - .github\n";
    $this->fake->touched = ['pipeline.yml', 'CLAUDE.md'];
    $invalid = ChangeRequest::factory()->onboarding()->status(ChangeRequestStatus::Queued)->create(['contact_id' => $this->site['contact']->id, 'website_id' => $this->site['website']->id]);
    expect(onboard($invalid)->error)->toContain('.github')->toContain('non può essere modificabile');
    expect($this->fake->ran('git push'))->toBeFalse();
});

it('merges an approved onboarding fast-forward, pushes the default branch and makes the site ready', function (): void {
    Event::fake([PipelineReady::class]);
    $this->request->update(['status' => ChangeRequestStatus::Approved]);

    (new MergeOnboarding($this->request->id))->handle(app(Merge::class));
    $request = $this->request->refresh();

    expect($request->status)->toBe(ChangeRequestStatus::Deployed)
        ->and($this->site['pipeline']->refresh()->status)->toBe(PipelineStatus::Ready)
        ->and($this->fake->calls('git fetch -- origin pipeline/onboarding'))->toHaveCount(1)
        ->and($this->fake->calls('git merge --ff-only -- origin/pipeline/onboarding'))->toHaveCount(1)
        ->and($this->fake->calls('git push -- origin main:main'))->toHaveCount(1)
        ->and($this->fake->calls('git push --delete -- origin pipeline/onboarding'))->toHaveCount(1)
        ->and($request->deploy_started_at)->not->toBeNull();
    Event::assertDispatched(PipelineReady::class, fn (PipelineReady $e): bool => $e->websiteId === $this->site['website']->id);
});

it('refuses to merge an onboarding branch that is not fast-forward or touches other files', function (): void {
    $this->request->update(['status' => ChangeRequestStatus::Approved]);
    $this->fake->on('git merge', FakeCommandRunner::fail('fatal: Not possible to fast-forward, aborting.', 128));

    (new MergeOnboarding($this->request->id))->handle(app(Merge::class));

    expect($this->request->refresh()->status)->toBe(ChangeRequestStatus::Failed)
        ->and($this->request->error)->toContain('fast-forward')
        ->and($this->site['pipeline']->refresh()->status)->toBe(PipelineStatus::Onboarding)
        ->and($this->fake->ran('git push'))->toBeFalse();
});
