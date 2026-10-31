<?php

declare(strict_types=1);

use App\Modules\Scheduling\Actions\OverrideJob;
use App\Modules\Scheduling\Actions\RunJob;
use App\Modules\Scheduling\Data\JobResult;
use App\Modules\Scheduling\Enums\RunTrigger;
use App\Modules\Scheduling\JobRegistry;
use App\Modules\Scheduling\Jobs\PruneJobRuns;
use App\Modules\Scheduling\Models\JobRun;
use App\Modules\Scheduling\Queries\JobRuns;
use App\Modules\Scheduling\Queries\ListJobs;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeJob;

function freshSchedule(): Schedule
{
    app()->forgetInstance(Schedule::class);

    return app(Schedule::class);
}

it('collects the jobs defined in code', function (): void {
    expect(array_keys(app(JobRegistry::class)->all()))->toContain('scheduling.prune-runs')
        ->and(app(JobRegistry::class)->get('scheduling.prune-runs'))->toBeInstanceOf(PruneJobRuns::class);
});

it('refuses duplicate or malformed job keys', function (): void {
    expect(fn () => (new JobRegistry([FakeJob::succeeding('a.b'), FakeJob::succeeding('a.b')]))->all())->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new JobRegistry([FakeJob::succeeding('NoModule')]))->all())->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(JobRegistry::class)->get('nope'))->toThrow(InvalidArgumentException::class);
});

it('records the exit code a run actually ended with, and its output', function (int $exitCode): void {
    registerJobs([new FakeJob('tests.exit', fn (): JobResult => new JobResult($exitCode, "ended with {$exitCode}"))]);

    $run = app(RunJob::class)->run('tests.exit', RunTrigger::Manual);

    expect($run->exitCode)->toBe($exitCode)
        ->and($run->output)->toBe("ended with {$exitCode}")
        ->and($run->finishedAt)->not->toBeNull()
        ->and($run->trigger)->toBe(RunTrigger::Manual)
        ->and($run->succeeded())->toBe($exitCode === 0);
})->with([0, 1, 2, 127]);

it('records an exception as a failed run rather than losing it', function (): void {
    registerJobs([new FakeJob('tests.boom', fn (): JobResult => throw new RuntimeException('boom'))]);

    $run = app(RunJob::class)->run('tests.boom', RunTrigger::Scheduler);

    expect($run->exitCode)->toBe(1)
        ->and($run->output)->toContain('RuntimeException: boom');
});

it('returns the job exit code from the artisan command the scheduler calls', function (int $exitCode): void {
    registerJobs([new FakeJob('tests.exit', fn (): JobResult => new JobResult($exitCode, 'out'))]);

    $this->artisan('scheduling:run', ['key' => 'tests.exit', '--trigger' => 'scheduler'])
        ->expectsOutputToContain('out')
        ->assertExitCode($exitCode);

    expect(JobRun::query()->sole()->trigger)->toBe(RunTrigger::Scheduler);
})->with([0, 3]);

it('binds the enabled jobs with their overrides when the scheduler is resolved', function (): void {
    registerJobs([
        new FakeJob('tests.default', fn (): JobResult => JobResult::success(), '0 1 * * *'),
        new FakeJob('tests.rescheduled', fn (): JobResult => JobResult::success(), '0 2 * * *'),
        new FakeJob('tests.paused', fn (): JobResult => JobResult::success(), '0 3 * * *'),
    ]);
    app(OverrideJob::class)->override('tests.rescheduled', null, '30 4 * * *');
    app(OverrideJob::class)->override('tests.paused', false, null);

    $events = collect(freshSchedule()->events())
        ->mapWithKeys(fn (Event $event): array => [$event->description => $event->expression]);

    expect($events->all())->toBe(['tests.default' => '0 1 * * *', 'tests.rescheduled' => '30 4 * * *'])
        ->and(collect(freshSchedule()->events())->first()?->command)->toContain('scheduling:run')->toContain('--trigger=scheduler');
});

it('leaves the scheduling tables alone on ordinary artisan commands', function (): void {
    app()->forgetInstance(Illuminate\Contracts\Console\Kernel::class);
    DB::enableQueryLog();

    $this->artisan('about')->assertSuccessful();

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'job_')))->toBeEmpty();
});

it('lists jobs with override and last run in a constant number of queries', function (): void {
    $jobs = array_map(fn (int $i): FakeJob => FakeJob::succeeding("tests.job{$i}"), range(1, 5));
    registerJobs($jobs);
    app(OverrideJob::class)->override('tests.job2', false, null);
    app(RunJob::class)->run('tests.job1', RunTrigger::Manual);
    $latest = app(RunJob::class)->run('tests.job1', RunTrigger::Manual);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $list = app(ListJobs::class)->get();
    $queries = count(DB::getQueryLog());

    registerJobs([...$jobs, ...array_map(fn (int $i): FakeJob => FakeJob::succeeding("tests.job{$i}"), range(6, 20))]);
    DB::flushQueryLog();
    app(ListJobs::class)->get();

    expect(DB::getQueryLog())->toHaveCount($queries)
        ->and($list)->toHaveCount(5)
        ->and($list[0]->lastRun?->id)->toBe($latest->id)
        ->and($list[1]->enabled)->toBeFalse()
        ->and($list[1]->overridden)->toBeTrue()
        ->and($list[2]->lastRun)->toBeNull()
        ->and(app(ListJobs::class)->find('tests.job2')?->enabled)->toBeFalse();
});

it('validates overrides and removes them when both values are default', function (): void {
    registerJobs([FakeJob::succeeding('tests.a')]);
    $override = app(OverrideJob::class);

    expect(fn () => $override->override('tests.a', null, 'not a cron'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $override->override('tests.missing', false, null))->toThrow(InvalidArgumentException::class);

    $override->override('tests.a', false, '0 * * * *');
    expect(app(ListJobs::class)->find('tests.a')?->overridden)->toBeTrue();

    $override->override('tests.a', null, '0 * * * *');
    expect(app(ListJobs::class)->find('tests.a')?->overridden)->toBeFalse();
});

it('prunes old runs and keeps recent ones', function (): void {
    $this->travelTo('2026-10-28 10:00:00');
    registerJobs([FakeJob::succeeding('tests.a')]);
    app(RunJob::class)->run('tests.a', RunTrigger::Manual);
    JobRun::query()->update(['started_at' => now()->subDays(PruneJobRuns::KEEP_DAYS + 1)]);
    app(RunJob::class)->run('tests.a', RunTrigger::Manual);

    $result = (new PruneJobRuns)->run();

    expect($result->succeeded())->toBeTrue()
        ->and($result->output)->toBe('Eliminate 1 esecuzioni.')
        ->and(app(JobRuns::class)->latest())->toHaveCount(1)
        ->and(app(JobRuns::class)->latest('tests.a', 10))->toHaveCount(1)
        ->and(app(JobRuns::class)->latest('tests.other'))->toBe([]);
});
