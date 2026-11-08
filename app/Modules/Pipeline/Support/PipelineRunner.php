<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use App\Modules\Pipeline\Data\CommandResult;
use App\Modules\Pipeline\Data\RunOutcome;
use App\Modules\Pipeline\Integrations\ClaudeCli;
use App\Modules\Pipeline\Integrations\CommandRunner;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use RuntimeException;

/**
 * The shared runner (docs/pipeline.md §3): a checkout per website under
 * the workspace root, a branch per request, one headless run of the CLI,
 * the path gate on what it touched, and the git plumbing the jobs need
 * afterwards. Every call is an argv list through CommandRunner; nothing
 * a client wrote is ever part of a shell string. Credentials come only
 * from the worker's environment (config/pipeline.php). run() never
 * pushes, and nothing here pushes the default branch except
 * pushDefault(), which only the deploy actions call.
 */
final readonly class PipelineRunner
{
    private const int GIT_TIMEOUT = 600;

    public function __construct(
        private CommandRunner $commands,
        private ClaudeCli $claude,
        private PipelineConfig $config,
        private CacheFactory $cache,
    ) {}

    public function workspace(int $websiteId): string
    {
        return $this->config->workspaceRoot().'/'.$websiteId;
    }

    public function previewDir(string $slug): string
    {
        return $this->config->previewRoot().'/'.$slug;
    }

    public function previewUrl(string $slug): string
    {
        return 'https://'.$slug.'.'.$this->config->previewDomain();
    }

    /**
     * One job at a time per checkout.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function locked(int $websiteId, callable $callback): mixed
    {
        $store = $this->cache->store()->getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The cache store cannot hold locks: the pipeline needs one (redis, database, file or array).');
        }
        $lock = $store->lock('pipeline:website:'.$websiteId, $this->config->workerTimeoutSeconds());

        try {
            return $lock->block(10, $callback);
        } catch (LockTimeoutException) {
            throw new WorkspaceBusyException('Il repository è occupato da un\'altra richiesta: riprova tra poco.');
        }
    }

    /**
     * A clean checkout of the default branch: cloned when missing, else
     * fetched and hard reset.
     *
     * @return string what was done, for the log
     */
    public function prepare(string $dir, string $url, string $defaultBranch): string
    {
        BranchName::assert($defaultBranch);
        $parent = dirname($dir);
        if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
            throw new RuntimeException("Impossibile creare la cartella di lavoro \"{$parent}\".");
        }

        if (! is_dir($dir.'/.git')) {
            $this->gitOrFail($parent, ['clone', '--branch', $defaultBranch, '--', $url, $dir], network: true);
            $this->gitOrFail($dir, ['checkout', '-f', '-B', $defaultBranch, 'origin/'.$defaultBranch]);

            return "Clonato {$url} ({$defaultBranch}).";
        }

        $this->gitOrFail($dir, ['remote', 'set-url', 'origin', '--', $url]);
        $this->gitOrFail($dir, ['fetch', '--prune', 'origin'], network: true);
        $this->gitOrFail($dir, ['checkout', '-f', '-B', $defaultBranch, 'origin/'.$defaultBranch]);
        $this->gitOrFail($dir, ['reset', '--hard', 'origin/'.$defaultBranch]);
        $this->gitOrFail($dir, ['clean', '-fdx']);

        return "Aggiornato {$url} ({$defaultBranch}).";
    }

    /**
     * The headless run on a fresh branch: what the CLI did, what it
     * touched, whether the policy accepts it, and whether it committed.
     */
    public function run(string $dir, string $defaultBranch, string $branch, string $prompt, PathPolicy $policy, ?string $systemPrompt = null): RunOutcome
    {
        BranchName::assert($defaultBranch);
        BranchName::assert($branch);
        $this->gitOrFail($dir, ['checkout', '-f', '-B', $branch, 'origin/'.$defaultBranch]);

        $promptFile = null;
        if ($systemPrompt !== null && trim($systemPrompt) !== '') {
            $promptFile = tempnam(sys_get_temp_dir(), 'pipeline-system-prompt-');
            if ($promptFile === false || file_put_contents($promptFile, $systemPrompt) === false) {
                throw new RuntimeException('Impossibile scrivere il prompt di sistema.');
            }
        }

        // The CLI may write anywhere under the checkout, .git included: the
        // repository's git configuration is put back as it was, so a run can't
        // redirect pushes, add filters or change where hooks live.
        $gitConfig = is_file($dir.'/.git/config') ? (string) file_get_contents($dir.'/.git/config') : null;

        try {
            $cli = $this->claude->run($prompt, $dir, $promptFile, $this->gitEnv());
        } finally {
            if ($promptFile !== null) {
                @unlink($promptFile);
            }
            if ($gitConfig !== null) {
                file_put_contents($dir.'/.git/config', $gitConfig);
            }
            foreach (['info/attributes', 'info/exclude'] as $file) {
                if (is_file($dir.'/.git/'.$file)) {
                    unlink($dir.'/.git/'.$file);
                }
            }
        }

        $touched = $this->touched($dir, 'origin/'.$defaultBranch, 'HEAD', includeWorkingTree: true);
        $refused = $policy->refused($touched);
        $commits = $this->commitsAhead($dir, 'origin/'.$defaultBranch, 'HEAD');

        $log = [];
        $log[] = $cli->timedOut ? 'Tempo scaduto: la run è stata interrotta.' : ($cli->succeeded ? 'Run completata.' : 'La run ha segnalato un errore.');
        if ($cli->costCents !== null || $cli->turns !== null) {
            $log[] = sprintf('Costo: %s · turni: %s', $cli->costCents === null ? '—' : '$'.number_format($cli->costCents / 100, 2, '.', ''), $cli->turns ?? '—');
        }
        $log[] = '';
        $log[] = $cli->resultText !== '' ? $cli->resultText : '(nessuna risposta)';
        if ($commits > 0) {
            $log[] = '';
            $log[] = trim($this->gitOrFail($dir, ['log', '--oneline', 'origin/'.$defaultBranch.'..HEAD'])->stdout);
            $log[] = trim($this->gitOrFail($dir, ['diff', '--stat', 'origin/'.$defaultBranch.'...HEAD'])->stdout);
        }

        // The build sees exactly the commits: anything uncommitted goes, ignored files included.
        $this->gitOrFail($dir, ['reset', '--hard', 'HEAD']);
        $this->gitOrFail($dir, ['clean', '-fdx']);

        return new RunOutcome(
            hasCommits: $commits > 0,
            log: implode("\n", $log),
            resultText: $cli->resultText,
            costCents: $cli->costCents,
            touchedPaths: $touched,
            refusedPaths: $refused,
            failure: $cli->timedOut ? 'La run ha superato il tempo massimo.' : ($cli->succeeded ? null : ($cli->resultText !== '' ? $cli->resultText : 'La run è fallita senza spiegazione.')),
        );
    }

    /**
     * Paths changed between two refs, plus (optionally) what the working
     * tree holds uncommitted: modified, untracked, and untracked-but-ignored
     * (an agent's write to .env or dist/ is a change too). Read with -z:
     * otherwise git quotes any path with a byte above 0x7F
     * ("content/caff\303\250.md") and the gate would match nothing.
     *
     * @return list<string>
     */
    public function touched(string $dir, string $from, string $to, bool $includeWorkingTree = false): array
    {
        $paths = $this->paths($this->gitOrFail($dir, ['diff', '--name-only', '-z', '--no-renames', $from.'...'.$to])->stdout);
        if ($includeWorkingTree) {
            $paths = [...$paths, ...$this->paths($this->gitOrFail($dir, ['diff', '--name-only', '-z', '--no-renames', 'HEAD'])->stdout)];
            $paths = [...$paths, ...$this->paths($this->gitOrFail($dir, ['ls-files', '-z', '--others', '--exclude-standard'])->stdout)];
            $paths = [...$paths, ...$this->paths($this->gitOrFail($dir, ['ls-files', '-z', '--others', '--ignored', '--exclude-standard'])->stdout)];
        }

        return array_values(array_unique($paths));
    }

    /**
     * How many commits $to has that $from doesn't.
     */
    public function commitsAhead(string $dir, string $from, string $to): int
    {
        return (int) trim($this->gitOrFail($dir, ['rev-list', '--count', $from.'..'.$to])->stdout);
    }

    /**
     * Whether origin has the branch (exit code 2 from ls-remote means it doesn't).
     */
    public function remoteBranchExists(string $dir, string $branch): bool
    {
        BranchName::assert($branch);
        $result = $this->git($dir, ['ls-remote', '--exit-code', '--heads', '--', 'origin', 'refs/heads/'.$branch], network: true);
        if ($result->exitCode === 2) {
            return false;
        }
        if (! $result->succeeded()) {
            throw new RuntimeException('git ls-remote fallito: '.mb_substr($result->output(), -2000));
        }

        return true;
    }

    public function show(string $dir, string $ref, string $path): ?string
    {
        $result = $this->git($dir, ['show', $ref.':'.$path]);

        return $result->succeeded() ? $result->stdout : null;
    }

    /**
     * Pushes a request branch. Never the default branch: that is pushDefault().
     */
    public function push(string $dir, string $branch, string $defaultBranch): void
    {
        BranchName::assert($branch);
        if ($branch === $defaultBranch) {
            throw new RuntimeException("Refusing to push the default branch \"{$branch}\" from a run.");
        }
        $this->gitOrFail($dir, ['push', '--force-with-lease', '--', 'origin', $branch.':'.$branch], network: true);
    }

    public function fetchBranch(string $dir, string $branch): void
    {
        BranchName::assert($branch);
        $this->gitOrFail($dir, ['fetch', '--', 'origin', $branch], network: true);
    }

    /**
     * origin/<branch> onto the default branch, fast-forward only.
     */
    public function fastForward(string $dir, string $defaultBranch, string $branch): string
    {
        BranchName::assert($defaultBranch);
        BranchName::assert($branch);
        $this->gitOrFail($dir, ['checkout', '-f', '-B', $defaultBranch, 'origin/'.$defaultBranch]);
        $merge = $this->git($dir, ['merge', '--ff-only', '--', 'origin/'.$branch]);
        if (! $merge->succeeded()) {
            throw new RuntimeException('Il branch non si può unire in fast-forward: il sito è cambiato nel frattempo. '.$merge->output());
        }

        return trim($merge->stdout);
    }

    public function pushDefault(string $dir, string $defaultBranch): void
    {
        BranchName::assert($defaultBranch);
        $this->gitOrFail($dir, ['push', '--', 'origin', $defaultBranch.':'.$defaultBranch], network: true);
    }

    public function deleteRemoteBranch(string $dir, string $branch): bool
    {
        BranchName::assert($branch);

        return $this->git($dir, ['push', '--delete', '--', 'origin', $branch], network: true)->succeeded();
    }

    /**
     * The manifest's own build or deploy command, written at onboarding
     * and approved by the owner. Repository code runs here: only ever in
     * the worker container. Only the deploy command gets the credentials
     * (it has to reach the hosting); the build never does.
     */
    public function shell(string $dir, string $command, int $timeoutSeconds, bool $withCredentials = false): CommandResult
    {
        return $this->commands->run(['sh', '-c', $command], $dir, max(1, $timeoutSeconds), $this->gitEnv($withCredentials));
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $dir, array $args, bool $network = false): CommandResult
    {
        return $this->commands->run(['git', ...$args], $dir, self::GIT_TIMEOUT, $this->gitEnv($network));
    }

    /**
     * @param  list<string>  $args
     */
    private function gitOrFail(string $dir, array $args, bool $network = false): CommandResult
    {
        $result = $this->git($dir, $args, $network);
        if (! $result->succeeded()) {
            throw new RuntimeException('git '.implode(' ', array_slice($args, 0, 2)).' fallito: '.mb_substr($result->output(), -2000));
        }

        return $result;
    }

    /**
     * The committer identity, no repository hooks, no prompts; credentials
     * only when the call talks to origin.
     *
     * @return array<string, string>
     */
    private function gitEnv(bool $network = false): array
    {
        $name = $this->config->gitName();
        $email = $this->config->gitEmail();
        $env = [
            'GIT_AUTHOR_NAME' => $name,
            'GIT_AUTHOR_EMAIL' => $email,
            'GIT_COMMITTER_NAME' => $name,
            'GIT_COMMITTER_EMAIL' => $email,
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'core.hooksPath',
            'GIT_CONFIG_VALUE_0' => '/dev/null',
        ];

        if ($network) {
            $env = [...$env, ...$this->config->gitEnv()];
        }

        return $env;
    }

    /**
     * git's -z output: NUL-terminated paths, neither quoted nor trimmed
     * (a name may start or end with a space).
     *
     * @return list<string>
     */
    private function paths(string $output): array
    {
        return array_values(array_filter(explode("\0", $output), fn (string $path): bool => $path !== ''));
    }
}
