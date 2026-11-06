<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Copies a build's output folder to the preview root: regular files and
 * directories only. A symbolic link anywhere in the output refuses the
 * copy, so a build can never publish the worker's filesystem.
 */
final class PreviewCopier
{
    /**
     * @return int files copied
     *
     * @throws RuntimeException
     */
    public function copy(string $source, string $target): int
    {
        $sourceReal = realpath($source);
        if ($sourceReal === false || ! is_dir($sourceReal) || is_link($source)) {
            throw new RuntimeException("La cartella di output \"{$source}\" non esiste.");
        }

        $this->delete($target);
        if (! mkdir($target, 0755, true) && ! is_dir($target)) {
            throw new RuntimeException("Impossibile creare la cartella dell'anteprima \"{$target}\".");
        }

        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceReal, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($sourceReal) + 1);
            if ($item->isLink()) {
                throw new RuntimeException("L'output contiene un link simbolico (\"{$relative}\"): anteprima rifiutata.");
            }
            $destination = $target.DIRECTORY_SEPARATOR.$relative;
            if ($item->isDir()) {
                if (! mkdir($destination, 0755, true) && ! is_dir($destination)) {
                    throw new RuntimeException("Impossibile creare \"{$destination}\".");
                }

                continue;
            }
            if (! $item->isFile()) {
                continue;
            }
            $real = realpath($item->getPathname());
            if ($real === false || ! str_starts_with($real, $sourceReal.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("\"{$relative}\" esce dalla cartella di output: anteprima rifiutata.");
            }
            if (! copy($real, $destination)) {
                throw new RuntimeException("Impossibile copiare \"{$relative}\".");
            }
            $count++;
        }

        return $count;
    }

    public function delete(string $directory): void
    {
        if (is_link($directory)) {
            unlink($directory);

            return;
        }
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
