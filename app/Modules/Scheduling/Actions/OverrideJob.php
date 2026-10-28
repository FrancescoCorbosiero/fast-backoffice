<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Scheduling\JobRegistry;
use App\Modules\Scheduling\Models\JobOverride;
use Cron\CronExpression;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Changes whether a job runs and when, without touching the code that
 * defines it. Passing null for both removes the override.
 */
final readonly class OverrideJob
{
    public function __construct(
        private JobRegistry $registry,
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
    ) {}

    public function override(string $key, ?bool $enabled, ?string $cron): void
    {
        $job = $this->registry->get($key);
        $cron = $cron === null ? null : trim($cron);

        if ($cron !== null && ! CronExpression::isValidExpression($cron)) {
            throw new InvalidArgumentException("\"{$cron}\" is not a cron expression.");
        }

        if ($cron === $job->defaultCron()) {
            $cron = null;
        }

        $this->db->transaction(function () use ($key, $enabled, $cron): void {
            if ($enabled === null && $cron === null) {
                JobOverride::query()->whereKey($key)->delete();
            } else {
                JobOverride::query()->updateOrCreate(['job_key' => $key], ['enabled' => $enabled, 'cron' => $cron]);
            }

            $this->audit->record('scheduling.job.overridden', details: ['job' => $key, 'enabled' => $enabled, 'cron' => $cron]);
        });
    }
}
