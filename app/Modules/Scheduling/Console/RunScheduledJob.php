<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Console;

use App\Modules\Scheduling\Actions\RunJob;
use App\Modules\Scheduling\Enums\RunTrigger;
use Illuminate\Console\Command;

/**
 * What the scheduler calls, one process per job, so the run's exit code is
 * the job's and overlapping runs are prevented per job.
 */
class RunScheduledJob extends Command
{
    protected $signature = 'scheduling:run {key : The job key, e.g. scheduling.prune-runs} {--trigger=manual : scheduler or manual}';

    protected $description = 'Run a scheduled job now and record the run';

    public function handle(RunJob $runJob): int
    {
        $trigger = RunTrigger::tryFrom((string) $this->option('trigger')) ?? RunTrigger::Manual;
        $run = $runJob->run((string) $this->argument('key'), $trigger);

        if ($run->output !== '') {
            $this->line($run->output);
        }

        return $run->exitCode ?? self::FAILURE;
    }
}
