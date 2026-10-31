<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Data;

/**
 * What a job run ended with: a process-style exit code (0 is success) and
 * whatever it has to say about it.
 */
final readonly class JobResult
{
    public function __construct(
        public int $exitCode,
        public string $output = '',
    ) {}

    public static function success(string $output = ''): self
    {
        return new self(0, $output);
    }

    public static function failure(string $output, int $exitCode = 1): self
    {
        return new self($exitCode === 0 ? 1 : $exitCode, $output);
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
