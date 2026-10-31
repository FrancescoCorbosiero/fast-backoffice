<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Data;

/**
 * A job as the screen sees it: its definition from code, the override
 * from the database (if any), and its last run.
 */
final readonly class JobData
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $defaultCron,
        public string $cron,
        public bool $enabled,
        public bool $overridden,
        public ?JobRunData $lastRun,
    ) {}
}
