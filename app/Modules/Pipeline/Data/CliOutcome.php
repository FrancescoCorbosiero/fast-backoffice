<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Data;

/**
 * One headless run of the Claude Code CLI, as its JSON result reports it.
 */
final readonly class CliOutcome
{
    public function __construct(
        public bool $succeeded,
        public string $resultText,
        public ?int $costCents,
        public ?int $turns,
        public bool $timedOut,
        public string $raw,
    ) {}
}
