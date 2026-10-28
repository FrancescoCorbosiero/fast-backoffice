<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Queries;

use App\Modules\Scheduling\Data\JobData;
use App\Modules\Scheduling\JobRegistry;
use App\Modules\Scheduling\Models\JobOverride;
use App\Modules\Scheduling\Models\JobRun;
use Illuminate\Database\Query\Builder;

final readonly class ListJobs
{
    public function __construct(private JobRegistry $registry) {}

    /**
     * Every job defined in code with its override and last run: three
     * queries however many jobs there are.
     *
     * @return list<JobData>
     */
    public function get(): array
    {
        $jobs = $this->registry->all();
        $overrides = JobOverride::query()->whereIn('job_key', array_keys($jobs))->get()->keyBy('job_key')->all();
        $lastRuns = JobRun::query()
            ->whereIn('id', fn (Builder $query) => $query->selectRaw('max(id)')->from('job_runs')->groupBy('job_key'))
            ->get()
            ->keyBy('job_key')
            ->all();

        $list = [];

        foreach ($jobs as $key => $job) {
            $override = $overrides[$key] ?? null;
            $lastRun = $lastRuns[$key] ?? null;

            $list[] = new JobData(
                key: $key,
                label: $job->label(),
                description: $job->description(),
                defaultCron: $job->defaultCron(),
                cron: $override->cron ?? $job->defaultCron(),
                enabled: $override->enabled ?? true,
                overridden: $override !== null,
                lastRun: $lastRun?->toData(),
            );
        }

        return $list;
    }

    public function find(string $key): ?JobData
    {
        foreach ($this->get() as $job) {
            if ($job->key === $key) {
                return $job;
            }
        }

        return null;
    }
}
