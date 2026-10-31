<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Jobs;

use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Scheduling\Data\JobResult;
use App\Modules\Scheduling\Models\JobRun;
use Illuminate\Support\Facades\Date;

/**
 * Keeps the run history to the last 90 days.
 */
final class PruneJobRuns implements ScheduledJob
{
    public const int KEEP_DAYS = 90;

    public function key(): string
    {
        return 'scheduling.prune-runs';
    }

    public function label(): string
    {
        return 'Pulizia cronologia esecuzioni';
    }

    public function description(): string
    {
        return 'Elimina le esecuzioni più vecchie di '.self::KEEP_DAYS.' giorni.';
    }

    public function defaultCron(): string
    {
        return '15 3 * * *';
    }

    public function run(): JobResult
    {
        $deleted = JobRun::query()->where('started_at', '<', Date::now()->subDays(self::KEEP_DAYS))->delete();

        return JobResult::success(sprintf('Eliminate %d esecuzioni.', is_int($deleted) ? $deleted : 0));
    }
}
