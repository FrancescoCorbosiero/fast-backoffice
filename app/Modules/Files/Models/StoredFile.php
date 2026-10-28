<?php

declare(strict_types=1);

namespace App\Modules\Files\Models;

use App\Kernel\Reference;
use App\Modules\Files\Data\StoredFileData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property string $owner
 * @property string $kind
 * @property string $filename
 * @property string $mime_type
 * @property int $size
 * @property string $sha256
 * @property CarbonImmutable $created_at
 */
class StoredFile extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function toData(): StoredFileData
    {
        return new StoredFileData(
            id: $this->id,
            owner: Reference::fromString($this->owner),
            kind: $this->kind,
            filename: $this->filename,
            mimeType: $this->mime_type,
            size: $this->size,
            sha256: $this->sha256,
            createdAt: $this->created_at,
        );
    }
}
