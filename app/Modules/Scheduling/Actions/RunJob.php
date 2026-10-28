<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\JobResult;
use App\Modules\Scheduling\Data\JobRunData;
use App\Modules\Scheduling\Enums\RunTrigger;
use App\Modules\Scheduling\JobRegistry;
use App\Modules\Scheduling\Models\JobRun;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Runs a job now and records the run: start, end, the exit code it really
 * ended with, and its output (an exception counts as exit code 1 with the
 * message as output). Used by the scheduler and by "run now".
 */
final readonly class RunJob
{
    public const int OUTPUT_LIMIT = 20_000;

    public function __construct(private JobRegistry $registry) {}

    public function run(string $key, RunTrigger $trigger): JobRunData
    {
        $job = $this->registry->get($key);

        $run = JobRun::query()->create([
            'job_key' => $key,
            'trigger' => $trigger,
            'started_at' => Date::now(),
            'output' => '',
        ]);

        try {
            $result = $job->run();
        } catch (Throwable $exception) {
            report($exception);
            $result = JobResult::failure(get_class($exception).': '.$exception->getMessage());
        }

        $run->forceFill([
            'finished_at' => Date::now(),
            'exit_code' => $result->exitCode,
            'output' => mb_substr($result->output, -self::OUTPUT_LIMIT),
        ])->save();

        return $run->toData();
    }
}
