<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Integrations;

use App\Modules\Pipeline\Data\CommandResult;

/**
 * Runs one process: an argv list (never a shell string), a working
 * directory, a timeout and the variables it may see. The only way the
 * module reaches git, the CLI or a build command; tests bind a fake.
 */
interface CommandRunner
{
    /**
     * @param  list<string>  $argv
     * @param  array<string, string>  $env  the variables the process gets on top of a minimal base; nothing else is inherited
     */
    public function run(array $argv, string $cwd, int $timeoutSeconds, array $env = []): CommandResult;
}
