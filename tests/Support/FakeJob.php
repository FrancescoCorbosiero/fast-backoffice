<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Scheduling\Data\JobResult;
use Closure;

/**
 * A scheduled job for tests: what it does is the closure it's given.
 */
final class FakeJob implements ScheduledJob
{
    public int $runs = 0;

    /**
     * @param  Closure(): JobResult  $behaviour
     */
    public function __construct(
        private readonly string $key,
        private readonly Closure $behaviour,
        private readonly string $cron = '0 * * * *',
    ) {}

    public static function succeeding(string $key = 'tests.fake', string $output = 'done'): self
    {
        return new self($key, fn (): JobResult => JobResult::success($output));
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Fake job '.$this->key;
    }

    public function description(): string
    {
        return 'A job for the tests.';
    }

    public function defaultCron(): string
    {
        return $this->cron;
    }

    public function run(): JobResult
    {
        $this->runs++;

        return ($this->behaviour)();
    }
}
