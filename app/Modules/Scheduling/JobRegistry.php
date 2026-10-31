<?php

declare(strict_types=1);

namespace App\Modules\Scheduling;

use App\Modules\Scheduling\Contracts\ScheduledJob;
use InvalidArgumentException;

/**
 * The jobs defined in code, collected from the container tag by the
 * service provider.
 */
final class JobRegistry
{
    /** @var array<string, ScheduledJob>|null */
    private ?array $jobs = null;

    /**
     * @param  iterable<ScheduledJob>  $definitions
     */
    public function __construct(private readonly iterable $definitions) {}

    /**
     * @return array<string, ScheduledJob> keyed by job key, in registration order
     */
    public function all(): array
    {
        if ($this->jobs !== null) {
            return $this->jobs;
        }

        $jobs = [];

        foreach ($this->definitions as $job) {
            $key = $job->key();

            if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_-]*)+$/', $key) !== 1) {
                throw new InvalidArgumentException("\"{$key}\" is not a job key (module.name).");
            }

            if (isset($jobs[$key])) {
                throw new InvalidArgumentException("Two scheduled jobs share the key \"{$key}\".");
            }

            $jobs[$key] = $job;
        }

        return $this->jobs = $jobs;
    }

    public function find(string $key): ?ScheduledJob
    {
        return $this->all()[$key] ?? null;
    }

    public function get(string $key): ScheduledJob
    {
        return $this->find($key) ?? throw new InvalidArgumentException("Unknown scheduled job \"{$key}\".");
    }
}
