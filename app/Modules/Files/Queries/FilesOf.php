<?php

declare(strict_types=1);

namespace App\Modules\Files\Queries;

use App\Kernel\Reference;
use App\Modules\Files\Data\StoredFileData;
use App\Modules\Files\Models\StoredFile;

final readonly class FilesOf
{
    /**
     * The files attached to a record, newest first; optionally one kind.
     *
     * @return list<StoredFileData>
     */
    public function get(Reference $owner, ?string $kind = null): array
    {
        return array_values(StoredFile::query()
            ->where('owner', $owner->toString())
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->orderByDesc('id')
            ->get()
            ->map(fn (StoredFile $file): StoredFileData => $file->toData())
            ->all());
    }

    /**
     * The files of many records at once (one query), keyed by reference.
     *
     * @param  list<Reference>  $owners
     * @return array<string, list<StoredFileData>>
     */
    public function forMany(array $owners, ?string $kind = null): array
    {
        $keys = array_map(fn (Reference $owner): string => $owner->toString(), $owners);
        $grouped = array_fill_keys($keys, []);

        if ($keys === []) {
            return $grouped;
        }

        $files = StoredFile::query()
            ->whereIn('owner', $keys)
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->orderByDesc('id')
            ->get();

        foreach ($files as $file) {
            $grouped[$file->owner][] = $file->toData();
        }

        return $grouped;
    }
}
