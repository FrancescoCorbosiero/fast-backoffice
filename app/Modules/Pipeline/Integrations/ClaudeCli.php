<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Integrations;

use App\Modules\Pipeline\Data\CliOutcome;
use App\Modules\Pipeline\Support\PipelineConfig;
use App\Support\Shape;
use JsonException;

/**
 * `claude -p` with the flags that keep a run inside the box: a budget, a
 * timeout, only the file tools and the git commands that stage, move and
 * commit, no web, no MCP servers, no hooks, no settings or memory from
 * the repository (--bare: the approved CLAUDE.md is passed explicitly by
 * the runner), no session left behind. The prompt is one argv element.
 */
final readonly class ClaudeCli
{
    public const array TOOLS = ['Read', 'Glob', 'Grep', 'Edit', 'Write', 'MultiEdit', 'Bash'];

    /** git mv places a request's photo, staged from pipeline-uploads/, into the site (docs/pipeline.md §10). */
    public const array ALLOWED = ['Read', 'Glob', 'Grep', 'Edit', 'Write', 'MultiEdit', 'Bash(git add:*)', 'Bash(git commit:*)', 'Bash(git status:*)', 'Bash(git diff:*)', 'Bash(git log:*)', 'Bash(git mv:*)'];

    public const array DISALLOWED = ['WebFetch', 'WebSearch', 'Task', 'NotebookEdit'];

    /**
     * The CLI's Bash tool needs a POSIX shell it can find through SHELL
     * ("No suitable shell found" otherwise): the allowlisted environment
     * has none, so the CLI, and only the CLI, gets bash (the worker image
     * installs it).
     */
    public const string SHELL = '/bin/bash';

    public function __construct(
        private CommandRunner $commands,
        private PipelineConfig $config,
    ) {}

    /**
     * @param  array<string, string>  $env  git identity and the like; SHELL and the API key are added here
     */
    public function run(string $prompt, string $cwd, ?string $systemPromptFile, array $env = []): CliOutcome
    {
        $argv = $this->argv($prompt, $systemPromptFile);
        $timeout = $this->config->timeoutSeconds();

        $env['SHELL'] = self::SHELL;

        $apiKey = $this->config->anthropicApiKey();
        if ($apiKey !== null) {
            $env['ANTHROPIC_API_KEY'] = $apiKey;
        }

        $result = $this->commands->run($argv, $cwd, $timeout, $env);

        return $this->parse($result->stdout, $result->stderr, $result->succeeded(), $result->timedOut);
    }

    /**
     * @return list<string>
     */
    public function argv(string $prompt, ?string $systemPromptFile): array
    {
        $model = $this->config->claudeModel();

        $argv = [
            $this->config->claudeBinary(),
            '-p', $prompt,
            '--output-format', 'json',
            '--max-budget-usd', number_format($this->config->maxCostUsd(), 2, '.', ''),
            '--permission-mode', 'acceptEdits',
            '--tools', implode(',', self::TOOLS),
            '--allowedTools', ...self::ALLOWED,
            '--disallowedTools', ...self::DISALLOWED,
            '--bare',
            '--strict-mcp-config',
            '--disable-slash-commands',
            '--no-session-persistence',
            '--setting-sources', 'user',
        ];

        if ($model !== null) {
            $argv[] = '--model';
            $argv[] = $model;
        }
        if ($systemPromptFile !== null) {
            $argv[] = '--append-system-prompt-file';
            $argv[] = $systemPromptFile;
        }

        return $argv;
    }

    private function parse(string $stdout, string $stderr, bool $exitOk, bool $timedOut): CliOutcome
    {
        $raw = trim($stdout);

        try {
            $json = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $json = null;
        }

        if (! is_array($json)) {
            $text = $raw !== '' ? $raw : trim($stderr);

            return new CliOutcome(false, $timedOut ? 'Tempo scaduto.' : $text, null, null, $timedOut, $raw);
        }

        $cost = $json['total_cost_usd'] ?? null;
        $isError = Shape::bool($json, 'is_error', false);
        $text = Shape::nullableString($json, 'result') ?? '';

        return new CliOutcome(
            succeeded: $exitOk && ! $isError && ! $timedOut,
            resultText: $text,
            costCents: is_numeric($cost) ? (int) round((float) $cost * 100) : null,
            turns: is_numeric($json['num_turns'] ?? null) ? (int) $json['num_turns'] : null,
            timedOut: $timedOut,
            raw: $raw,
        );
    }
}
