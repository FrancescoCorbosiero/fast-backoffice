<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

use App\Modules\Pipeline\Data\ChangeRequestAttachmentData;
use RuntimeException;

/**
 * The run's pipeline-uploads/ folder (docs/pipeline.md §10): the
 * request's photos copied into the checkout before the CLI starts,
 * untracked and excluded from bulk adds, and removed after it, before
 * the path gate looks. Nothing here follows a symbolic link: the
 * checkout holds a client's repository.
 */
final readonly class WorkspaceUploads
{
    public const string DIR = ChangeRequestAttachmentData::WORKSPACE_DIR;

    private const string NAME = '/^[0-9]{1,3}-[a-z0-9-]{1,60}\.(jpg|png|webp)$/';

    public function __construct(private AttachmentStore $store) {}

    /**
     * Creates the folder. A repository that already has anything of that
     * name at its root (a folder, a file, a link) can't take photos: its
     * entry is left alone and the run ends.
     *
     * @throws RuntimeException
     */
    public function create(string $checkout): void
    {
        $dir = $checkout.'/'.self::DIR;
        if (file_exists($dir) || is_link($dir)) {
            throw new RuntimeException('Il repository ha già "'.self::DIR.'" alla radice: rinominalo, o la run non può usare le foto allegate.');
        }
        if (! mkdir($dir, 0700)) {
            throw new RuntimeException('Impossibile creare "'.self::DIR.'" nella cartella di lavoro.');
        }
    }

    /**
     * Copies the photos in, each checked against the checksum it was
     * stored with, and keeps the folder out of `git add -A` and `git add .`:
     * a photo goes in only with an explicit `git add -f`.
     *
     * @param  list<WorkspaceUpload>  $uploads
     *
     * @throws RuntimeException
     */
    public function fill(string $checkout, array $uploads): void
    {
        $dir = $checkout.'/'.self::DIR;
        foreach ($uploads as $upload) {
            $this->copy($upload, $dir);
        }

        $info = $checkout.'/.git/info';
        if (! is_dir($info) && ! mkdir($info, 0755, true) && ! is_dir($info)) {
            throw new RuntimeException('Impossibile scrivere .git/info/exclude.');
        }
        file_put_contents($info.'/exclude', '/'.self::DIR."/\n");
    }

    /**
     * Gone from the checkout, whatever the run made of it (a folder, a
     * file, a link), never following a link. A failure ends the run
     * before anything is built or pushed.
     *
     * @throws RuntimeException
     */
    public function remove(string $checkout): void
    {
        $path = $checkout.'/'.self::DIR;
        self::removeTree($path);
        clearstatcache();
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Impossibile togliere "'.self::DIR.'" dalla cartella di lavoro.');
        }
    }

    private function copy(WorkspaceUpload $upload, string $dir): void
    {
        if (preg_match(self::NAME, $upload->name) !== 1) {
            throw new RuntimeException("Nome non valido per una foto allegata: \"{$upload->name}\".");
        }

        // "x": never follows or overwrites anything already there.
        $target = fopen($dir.'/'.$upload->name, 'xb');
        if ($target === false) {
            throw new RuntimeException("Impossibile copiare la foto {$upload->name}.");
        }
        $source = $this->store->readStream($upload->storedPath);
        $hash = hash_init('sha256');

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException("Impossibile leggere la foto {$upload->name}.");
                }
                hash_update($hash, $chunk);
                if (fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException("Impossibile copiare la foto {$upload->name}.");
                }
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        if (! hash_equals($upload->sha256, hash_final($hash))) {
            throw new RuntimeException("La foto {$upload->name} non corrisponde più a quella ricevuta (sha256 diverso).");
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path.'/'.$entry);
            }
        }
        rmdir($path);
    }
}
