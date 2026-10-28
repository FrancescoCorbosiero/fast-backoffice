<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Data;

use App\Modules\Scheduling\Enums\RunTrigger;
use Carbon\CarbonImmutable;

final readonly class JobRunData
{
    public function __construct(
        public int $id,
        public string $jobKey,
        public RunTrigger $trigger,
        public CarbonImmutable $startedAt,
        public ?CarbonImmutable $finishedAt,
        public ?int $exitCode,
        public string $output,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
