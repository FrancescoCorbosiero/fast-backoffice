<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Support;

/**
 * The address of a repository, in any of the forms git accepts:
 *
 *     https://github.com/studio/sito.git
 *     ssh://git@github.com/studio/sito.git
 *     git://github.com/studio/sito.git
 *     git@github.com:studio/sito.git          (the scp-like form, no scheme)
 *
 * The pipeline worker clones with a deploy key, so the SSH forms are the
 * ones a private repository is registered with.
 */
final class GitUrl
{
    /**
     * The host the address points at, lower-cased, or null when the
     * string is not a repository address.
     */
    public static function host(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._-]+@([A-Za-z0-9.-]+):(?!\/\/)[^\s:]+$/', $url, $matches) === 1) {
            return mb_strtolower($matches[1]);
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme']) || ! in_array($parts['scheme'], ['http', 'https', 'ssh', 'git', 'git+ssh', 'ssh+git'], true)) {
            return null;
        }

        return mb_strtolower($parts['host']);
    }
}
