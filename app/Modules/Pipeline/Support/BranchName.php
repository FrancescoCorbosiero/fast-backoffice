<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use InvalidArgumentException;

/**
 * Branch names the runner passes to git: plain characters only, never
 * something git could read as an option.
 */
final class BranchName
{
    public static function assert(string $name): string
    {
        if ($name === '' || str_starts_with($name, '-') || preg_match('/^[A-Za-z0-9._\/-]+$/', $name) !== 1 || str_contains($name, '..') || str_ends_with($name, '/') || str_ends_with($name, '.lock')) {
            throw new InvalidArgumentException("\"{$name}\" is not a branch name the pipeline accepts.");
        }

        return $name;
    }
}
