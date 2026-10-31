<?php

declare(strict_types=1);

namespace App\Modules\Files\Queries;

use App\Modules\Files\Data\StoredFileData;
use App\Modules\Files\Models\StoredFile;
use Illuminate\Contracts\Filesystem\Factory;
use RuntimeException;

final readonly class ReadFile
{
    public function __construct(private Factory $filesystems) {}

    public function find(int $fileId): ?StoredFileData
    {
        return StoredFile::query()->find($fileId)?->toData();
    }

    /**
     * The file's bytes, verified against the checksum recorded when it was stored.
     */
    public function contents(int $fileId): string
    {
        $file = StoredFile::query()->findOrFail($fileId);
        $contents = $this->filesystems->disk($file->disk)->get($file->path);

        if ($contents === null) {
            throw new RuntimeException("Stored file {$fileId} is missing from disk {$file->disk} at {$file->path}.");
        }

        if (hash('sha256', $contents) !== $file->sha256) {
            throw new RuntimeException("Stored file {$fileId} does not match its checksum.");
        }

        return $contents;
    }
}
