<?php

declare(strict_types=1);

namespace App\Modules\Scheduling;

use App\Modules\Scheduling\Enums\RunTrigger;
use App\Modules\Scheduling\Models\JobOverride;
use Illuminate\Console\Scheduling\Schedule;
use Throwable;

/**
 * Binds the enabled jobs into Laravel's scheduler, applying the database
 * overrides. Runs when the Schedule is resolved (schedule:run,
 * schedule:work, schedule:list), never on an ordinary artisan command.
 */
final readonly class Scheduler
{
    public function __construct(private JobRegistry $registry) {}

    public function bind(Schedule $schedule): void
    {
        $jobs = $this->registry->all();

        if ($jobs === []) {
            return;
        }

        try {
            $overrides = JobOverride::query()->whereIn('job_key', array_keys($jobs))->get()->keyBy('job_key')->all();
        } catch (Throwable) {
            // Before the first migration there is no table: schedule nothing.
            return;
        }

        foreach ($jobs as $key => $job) {
            $override = $overrides[$key] ?? null;

            if (($override->enabled ?? true) === false) {
                continue;
            }

            $schedule->command('scheduling:run', [$key, '--trigger='.RunTrigger::Scheduler->value])
                ->cron($override->cron ?? $job->defaultCron())
                ->name($key)
                ->withoutOverlapping()
                ->onOneServer();
        }
    }
}
