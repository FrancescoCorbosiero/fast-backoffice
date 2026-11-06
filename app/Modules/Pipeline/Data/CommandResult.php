<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Data;

/**
 * What a process ended with.
 */
final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $timedOut = false,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }

    public function output(): string
    {
        return trim($this->stdout."\n".$this->stderr);
    }
}
