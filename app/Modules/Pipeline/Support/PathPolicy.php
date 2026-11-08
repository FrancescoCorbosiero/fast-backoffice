<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use App\Modules\Pipeline\Data\ChangeRequestAttachmentData;
use App\Modules\Pipeline\Data\PipelineManifest;

/**
 * Which files a run may touch. The prompt tells the agent the rules;
 * this enforces them on what the run actually changed, before anything
 * is built or pushed: nothing hidden (dotfiles, .git, .claude, .github),
 * never the manifest or CLAUDE.md on a change run, never the files that
 * decide what a build command executes, never the pipeline-uploads/
 * folder the run's photos wait in, and only under the manifest's
 * editable paths.
 */
final readonly class PathPolicy
{
    public const array PROTECTED = ['pipeline.yml', 'CLAUDE.md'];

    private const array BUILD_FILES = [
        'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lockb', 'bun.lock',
        'composer.json', 'composer.lock', 'Makefile', 'Gemfile', 'Gemfile.lock', 'pyproject.toml', 'Pipfile', 'Pipfile.lock',
        'config.toml', 'config.yaml', 'config.yml', 'netlify.toml', 'vercel.json', 'wrangler.toml',
        'Dockerfile', 'Procfile',
    ];

    private const array BUILD_PATTERNS = [
        '/^hugo\.(toml|yaml|yml|json)$/',
        '/^requirements[^\/]*\.txt$/',
        '/^docker-compose[^\/]*\.ya?ml$/',
        '/\.config\.(js|ts|mjs|cjs|mts|cts)$/',
        '/^_config\.ya?ml$/',
    ];

    /**
     * @param  list<string>  $allowed  files, or directory prefixes, relative to the repository
     * @param  bool  $protectManifest  false only for onboarding, which exists to write the protected files
     */
    public function __construct(
        public array $allowed,
        public bool $protectManifest = true,
    ) {}

    public static function onboarding(): self
    {
        return new self(self::PROTECTED, protectManifest: false);
    }

    public static function forManifest(PipelineManifest $manifest): self
    {
        return new self($manifest->editablePaths);
    }

    /**
     * Why a path is refused regardless of the allowed list, or null.
     */
    public static function refuseAlways(string $path, bool $protectManifest = true): ?string
    {
        // git -z hands a name over exactly as it is: one padded with spaces
        // or holding a control character would otherwise be checked as
        // another path (" content/x.js" is not under content/).
        if ($path !== trim($path) || preg_match('/[\x01-\x1F\x7F]/', $path) === 1) {
            return 'nome di file non valido';
        }

        $path = self::normalise($path);
        if ($path === '' || $path === '.') {
            return 'percorso vuoto';
        }
        if (str_starts_with($path, '/') || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            return 'percorso fuori dal repository';
        }
        if (preg_match('#(^|/)\.[^/]#', $path) === 1) {
            return 'file o cartella nascosti';
        }
        // The run's photos wait there to be moved into the site, never to be committed there.
        if ($path === ChangeRequestAttachmentData::WORKSPACE_DIR || str_starts_with($path, ChangeRequestAttachmentData::WORKSPACE_DIR.'/')) {
            return 'cartella degli allegati';
        }
        if ($protectManifest && in_array($path, self::PROTECTED, true)) {
            return 'file della pipeline';
        }

        $name = basename($path);
        if (in_array($name, self::BUILD_FILES, true)) {
            return 'file di build o configurazione';
        }
        foreach (self::BUILD_PATTERNS as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return 'file di build o configurazione';
            }
        }

        return null;
    }

    public function refuses(string $path): ?string
    {
        $reason = self::refuseAlways($path, $this->protectManifest);
        if ($reason !== null) {
            return $reason;
        }

        $path = self::normalise($path);
        foreach ($this->allowed as $allowed) {
            $allowed = rtrim(self::normalise($allowed), '/');
            if ($allowed === '' || $allowed === '.') {
                continue;
            }
            if ($path === $allowed || str_starts_with($path, $allowed.'/')) {
                return null;
            }
        }

        return 'fuori dai percorsi modificabili';
    }

    /**
     * The paths this policy refuses, each with its reason.
     *
     * @param  list<string>  $paths
     * @return list<string> "path (reason)"
     */
    public function refused(array $paths): array
    {
        $refused = [];
        foreach ($paths as $path) {
            $reason = $this->refuses($path);
            if ($reason !== null) {
                $refused[] = self::normalise($path).' ('.$reason.')';
            }
        }

        return $refused;
    }

    public static function normalise(string $path): string
    {
        $path = trim($path);
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return $path;
    }
}
