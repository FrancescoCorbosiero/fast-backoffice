<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Modules\Files\Models\StoredFile;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\ConnectionInterface;

/**
 * Removes a stored file and its record. Fiscal documents are never
 * deleted: this serves drafts and superseded renders only.
 */
final readonly class DeleteFile
{
    public function __construct(
        private Factory $filesystems,
        private ConnectionInterface $db,
    ) {}

    public function delete(int $fileId): void
    {
        $this->db->transaction(function () use ($fileId): void {
            $file = StoredFile::query()->lockForUpdate()->find($fileId);

            if ($file === null) {
                return;
            }

            $file->delete();
            $this->filesystems->disk($file->disk)->delete($file->path);
        });
    }
}
