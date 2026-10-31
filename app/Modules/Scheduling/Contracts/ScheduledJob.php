<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Contracts;

use App\Modules\Scheduling\Data\JobResult;

/**
 * A recurring job, defined in code by the module that owns it and
 * registered in that module's service provider:
 *
 *     $this->app->tag([PollInbox::class], ScheduledJob::TAG);
 *
 * The database stores only overrides (enabled, schedule) and run history.
 */
interface ScheduledJob
{
    public const string TAG = 'scheduling.jobs';

    /**
     * Stable identifier, module first: "invoicing.poll-inbox".
     */
    public function key(): string;

    public function label(): string;

    public function description(): string;

    /**
     * A five-field cron expression, in the app's timezone.
     */
    public function defaultCron(): string;

    public function run(): JobResult;
}
