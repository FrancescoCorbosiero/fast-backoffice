<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Data;

/**
 * What one headless run of the CLI left behind on the request's branch.
 *
 * @param  list<string>  $touchedPaths
 * @param  list<string>  $refusedPaths  paths outside the policy, when the run was refused
 */
final readonly class RunOutcome
{
    /**
     * @param  list<string>  $touchedPaths
     * @param  list<string>  $refusedPaths
     */
    public function __construct(
        public bool $hasCommits,
        public string $log,
        public string $resultText,
        public ?int $costCents,
        public array $touchedPaths,
        public array $refusedPaths = [],
        public ?string $failure = null,
    ) {}

    public function wasRefused(): bool
    {
        return $this->refusedPaths !== [];
    }

    public function failed(): bool
    {
        return $this->failure !== null;
    }
}
