<?php

declare(strict_types=1);

namespace App\Modules\Scheduling;

use App\Modules\Scheduling\Console\RunScheduledJob;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Scheduling\Filament\SchedulingPlugin;
use App\Modules\Scheduling\Jobs\PruneJobRuns;
use App\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class SchedulingServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Scheduling';
    }

    protected function plugin(): ?string
    {
        return SchedulingPlugin::class;
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(JobRegistry::class, function (): JobRegistry {
            /** @var iterable<ScheduledJob> $jobs */
            $jobs = $this->app->tagged(ScheduledJob::TAG);

            return new JobRegistry($jobs);
        });
        $this->app->tag([PruneJobRuns::class], ScheduledJob::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([RunScheduledJob::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->app->make(Scheduler::class)->bind($schedule);
        });
    }
}
