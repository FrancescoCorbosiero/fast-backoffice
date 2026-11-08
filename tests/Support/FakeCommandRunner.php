<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Pipeline\Data\CommandResult;
use App\Modules\Pipeline\Integrations\CommandRunner;
use Closure;

/**
 * The runner's view of git, the CLI and the build, scripted: every call is
 * recorded (argv, cwd, timeout, env), and answered by the first matching
 * handler or by defaults that play a successful run.
 */
final class FakeCommandRunner implements CommandRunner
{
    /** @var list<array{argv: list<string>, cwd: string, timeout: int, env: array<string, string>}> */
    public array $calls = [];

    /** @var list<array{0: string, 1: CommandResult|Closure}> prefix → result, checked in order */
    private array $handlers = [];

    public int $commitsAhead = 1;

    /** @var list<string> what `git diff --name-only` reports */
    public array $touched = ['content/orari.md'];

    /** @var list<string> what `git ls-files --others` reports */
    public array $untracked = [];

    /** @var list<string> what `git ls-files --others --ignored` reports */
    public array $ignored = [];

    /** @var array<string, string> path → content, answered by `git show <ref>:<path>` */
    public array $files = [];

    /** @var array<string, mixed> the CLI's JSON result */
    public array $claudeResult = ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'Ho aggiornato gli orari del sabato.', 'total_cost_usd' => 0.1234, 'num_turns' => 4];

    public bool $claudeTimesOut = false;

    /** @var array<string, string> files the build writes into the output folder, relative to it */
    public array $buildOutput = ['index.html' => '<h1>Anteprima</h1>', 'css/site.css' => 'body{}'];

    public string $outputDir = 'dist';

    /**
     * @param  list<string>  $editable
     */
    public static function manifest(string $build = 'npm ci && npm run build', string $outputDir = 'dist', array $editable = ['content', 'layouts'], ?string $deploy = null): string
    {
        $yaml = "build: \"{$build}\"\noutput_dir: {$outputDir}\neditable_paths:\n";
        foreach ($editable as $path) {
            $yaml .= "  - {$path}\n";
        }

        return $deploy === null ? $yaml : $yaml."deploy: \"{$deploy}\"\n";
    }

    public static function ok(string $stdout = ''): CommandResult
    {
        return new CommandResult(0, $stdout, '');
    }

    public static function fail(string $stderr = 'boom', int $code = 1): CommandResult
    {
        return new CommandResult($code, '', $stderr);
    }

    /**
     * A scripted answer for every call whose argv starts with the prefix
     * (space-joined), e.g. "git push" or "sh -c npm".
     */
    public function on(string $prefix, CommandResult|Closure $result): self
    {
        $this->handlers[] = [$prefix, $result];

        return $this;
    }

    public function run(array $argv, string $cwd, int $timeoutSeconds, array $env = []): CommandResult
    {
        $this->calls[] = ['argv' => $argv, 'cwd' => $cwd, 'timeout' => $timeoutSeconds, 'env' => $env];
        $joined = implode(' ', $argv);

        foreach ($this->handlers as [$prefix, $result]) {
            if (str_starts_with($joined, $prefix)) {
                $answer = $result instanceof Closure ? $result($argv, $cwd) : $result;

                return $answer instanceof CommandResult ? $answer : self::ok();
            }
        }

        return $this->defaults($argv, $cwd);
    }

    /**
     * @return list<array{argv: list<string>, cwd: string, timeout: int, env: array<string, string>}>
     */
    public function calls(string $prefix): array
    {
        return array_values(array_filter($this->calls, fn (array $call): bool => str_starts_with(implode(' ', $call['argv']), $prefix)));
    }

    public function ran(string $prefix): bool
    {
        return $this->calls($prefix) !== [];
    }

    /**
     * @param  list<string>  $argv
     */
    private function defaults(array $argv, string $cwd): CommandResult
    {
        $tool = $argv[0] ?? '';

        if ($tool === 'git') {
            return $this->git(array_slice($argv, 1), $cwd);
        }

        if ($tool === 'sh') {
            $target = $cwd.'/'.$this->outputDir;
            foreach ($this->buildOutput as $path => $content) {
                if (! is_dir(dirname($target.'/'.$path))) {
                    mkdir(dirname($target.'/'.$path), 0755, true);
                }
                file_put_contents($target.'/'.$path, $content);
            }

            return self::ok("build ok\n");
        }

        // The CLI.
        if ($this->claudeTimesOut) {
            return new CommandResult(124, '', '', true);
        }

        return self::ok((string) json_encode($this->claudeResult));
    }

    /**
     * @param  list<string>  $args
     */
    private function git(array $args, string $cwd): CommandResult
    {
        $command = $args[0] ?? '';

        if ($command === 'clone') {
            $dir = end($args);
            mkdir($dir.'/.git', 0755, true);
            file_put_contents($dir.'/.git/config', "[core]\n\trepositoryformatversion = 0\n[remote \"origin\"]\n\turl = ".$args[count($args) - 2]."\n");

            return self::ok();
        }
        if ($command === 'rev-list') {
            return self::ok((string) $this->commitsAhead."\n");
        }
        if ($command === 'diff' && in_array('--name-only', $args, true)) {
            return self::ok(self::listing($this->touched, $args));
        }
        if ($command === 'diff') {
            return self::ok(" content/orari.md | 2 +-\n");
        }
        if ($command === 'ls-files') {
            return self::ok(self::listing(in_array('--ignored', $args, true) ? $this->ignored : $this->untracked, $args));
        }
        if ($command === 'log') {
            return self::ok("abc1234 Aggiorna gli orari del sabato\n");
        }
        if ($command === 'show') {
            [, $path] = explode(':', $args[1] ?? ':', 2);
            if (! isset($this->files[$path])) {
                return self::fail("fatal: path '{$path}' does not exist", 128);
            }

            return self::ok($this->files[$path]);
        }
        if ($command === 'merge') {
            return self::ok("Fast-forward\n");
        }

        return self::ok();
    }

    /**
     * A list of paths the way git prints it: each one NUL-terminated with
     * -z, otherwise one per line with core.quotePath's C-style quoting of
     * anything unusual (bytes above 0x7F, control characters, quotes and
     * backslashes), so "caffè.md" comes out as "caff\303\250.md".
     *
     * @param  list<string>  $paths
     * @param  list<string>  $args
     */
    private static function listing(array $paths, array $args): string
    {
        if (in_array('-z', $args, true)) {
            return implode('', array_map(fn (string $path): string => $path."\0", $paths));
        }

        return implode('', array_map(fn (string $path): string => self::quoted($path)."\n", $paths));
    }

    private static function quoted(string $path): string
    {
        if (preg_match('/[\x00-\x1F\x7F-\xFF"\\\\]/', $path) !== 1) {
            return $path;
        }

        $quoted = '';
        foreach (str_split($path) as $char) {
            $byte = ord($char);
            $quoted .= match (true) {
                $char === '"' => '\\"',
                $char === '\\' => '\\\\',
                $char === "\t" => '\\t',
                $char === "\n" => '\\n',
                $byte < 0x20 || $byte >= 0x7F => sprintf('\\%03o', $byte),
                default => $char,
            };
        }

        return '"'.$quoted.'"';
    }
}
