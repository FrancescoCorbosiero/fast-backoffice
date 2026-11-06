<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Integrations;

use App\Modules\Pipeline\Data\CommandResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Symfony Process with explicit argv and an allowlisted environment: the
 * worker's own variables (database password, app key, every other
 * secret) are unset for the child, which gets PATH, HOME and locale plus
 * exactly what the caller passes.
 */
final readonly class SymfonyCommandRunner implements CommandRunner
{
    private const array KEEP = ['PATH', 'HOME', 'LANG', 'LC_ALL', 'TERM', 'TMPDIR'];

    public function run(array $argv, string $cwd, int $timeoutSeconds, array $env = []): CommandResult
    {
        $process = new Process($argv, $cwd, $this->environment($env), null, (float) max(1, $timeoutSeconds));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new CommandResult(124, $process->getOutput(), $process->getErrorOutput(), true);
        }

        return new CommandResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string|false>
     */
    private function environment(array $env): array
    {
        $inherited = array_merge(array_keys($_ENV), array_keys(getenv()), array_keys($_SERVER));
        $base = array_fill_keys(array_map('strval', $inherited), false);

        foreach (self::KEEP as $name) {
            $value = getenv($name);
            if (is_string($value)) {
                $base[$name] = $value;
            }
        }

        return [...$base, ...$env];
    }
}
