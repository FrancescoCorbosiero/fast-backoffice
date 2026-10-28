<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Queries;

use App\Modules\Scheduling\Data\JobRunData;
use App\Modules\Scheduling\Models\JobRun;

final readonly class JobRuns
{
    /**
     * @return list<JobRunData>
     */
    public function latest(?string $jobKey = null, int $limit = 50): array
    {
        return array_values(JobRun::query()
            ->when($jobKey !== null, fn ($query) => $query->where('job_key', $jobKey))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (JobRun $run): JobRunData => $run->toData())
            ->all());
    }

    public function find(int $id): ?JobRunData
    {
        return JobRun::query()->find($id)?->toData();
    }
}
