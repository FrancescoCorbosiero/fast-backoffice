<?php

declare(strict_types=1);

use App\Modules\Scheduling\Actions\RunJob;
use App\Modules\Scheduling\Data\JobResult;
use App\Modules\Scheduling\Enums\RunTrigger;
use App\Modules\Scheduling\Filament\Pages\JobsPage;
use App\Modules\Scheduling\Filament\Resources\JobRuns\Pages\ListJobRuns;
use App\Modules\Scheduling\Models\JobRun;
use App\Modules\Scheduling\Queries\ListJobs;
use Livewire\Livewire;
use Tests\Support\FakeJob;

beforeEach(function (): void {
    signInAsOwner();
});

it('lists the jobs and runs one now', function (): void {
    $job = new FakeJob('tests.page', fn (): JobResult => JobResult::success('ran from the page'));
    registerJobs([$job]);

    $this->get('/admin/system/jobs')->assertOk()->assertSee('Fake job tests.page');

    Livewire::test(JobsPage::class)
        ->assertSee('tests.page')
        ->callTableAction('run', 'tests.page')
        ->assertNotified();

    expect($job->runs)->toBe(1)
        ->and(JobRun::query()->sole()->output)->toBe('ran from the page')
        ->and(JobRun::query()->sole()->trigger)->toBe(RunTrigger::Manual);
});

it('pauses, reschedules and resets a job from the page', function (): void {
    registerJobs([FakeJob::succeeding('tests.page')]);
    $jobs = app(ListJobs::class);

    Livewire::test(JobsPage::class)->callTableAction('toggle', 'tests.page');
    expect($jobs->find('tests.page')?->enabled)->toBeFalse();

    Livewire::test(JobsPage::class)->callTableAction('schedule', 'tests.page', ['cron' => '*/5 * * * *']);
    expect($jobs->find('tests.page')?->cron)->toBe('*/5 * * * *')
        ->and($jobs->find('tests.page')?->enabled)->toBeFalse();

    Livewire::test(JobsPage::class)->callTableAction('schedule', 'tests.page', ['cron' => 'nope'])->assertHasFormErrors(['cron']);

    Livewire::test(JobsPage::class)->callTableAction('reset', 'tests.page');
    expect($jobs->find('tests.page')?->overridden)->toBeFalse()
        ->and($jobs->find('tests.page')?->enabled)->toBeTrue();
});

it('shows the run history', function (): void {
    registerJobs([new FakeJob('tests.page', fn (): JobResult => JobResult::failure('it broke', 4))]);
    $run = app(RunJob::class)->run('tests.page', RunTrigger::Scheduler);

    $this->get('/admin/system/job-runs')->assertOk()->assertSee('tests.page');
    $this->get("/admin/system/job-runs/{$run->id}")->assertOk()->assertSee('it broke');

    Livewire::test(ListJobRuns::class)->assertCanSeeTableRecords(JobRun::all());
});
