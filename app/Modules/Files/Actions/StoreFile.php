<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Kernel\Reference;
use App\Modules\Files\Data\StoredFileData;
use App\Modules\Files\Models\StoredFile;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Stores a generated or received file (XML, PDF, PEC envelope) for a
 * record of another module, with its checksum. Paths are never reused.
 */
final readonly class StoreFile
{
    public function __construct(
        private Factory $filesystems,
        private Repository $config,
    ) {}

    public function store(Reference $owner, string $kind, string $filename, string $contents, string $mimeType): StoredFileData
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $kind) !== 1) {
            throw new InvalidArgumentException("\"{$kind}\" is not a file kind (snake_case).");
        }

        $filename = basename(trim($filename));

        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new InvalidArgumentException('A stored file needs a file name.');
        }

        $disk = $this->disk();
        $path = sprintf('%s/%s/%s-%s', $owner->type, str_replace(':', '/', $owner->key), Str::lower((string) Str::ulid()), $filename);

        if (! $this->filesystems->disk($disk)->put($path, $contents)) {
            throw new RuntimeException("Could not write {$path} to disk {$disk}.");
        }

        $file = StoredFile::query()->create([
            'disk' => $disk,
            'path' => $path,
            'owner' => $owner->toString(),
            'kind' => $kind,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'created_at' => Date::now(),
        ]);

        return $file->toData();
    }

    private function disk(): string
    {
        $disk = $this->config->get('backoffice.files_disk');

        return is_string($disk) ? $disk : 'files-local';
    }
}
