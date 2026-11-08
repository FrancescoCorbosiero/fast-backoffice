<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Models;

use App\Modules\Pipeline\Data\ChangeRequestAttachmentData;
use App\Modules\Pipeline\Database\Factories\ChangeRequestAttachmentFactory;
use App\Modules\Pipeline\Enums\ImageFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image on a change request (docs/pipeline.md §10). The file lives on
 * the pipeline's private disk under stored_path; removed_at is set when
 * retention deletes it, and the row stays.
 *
 * @property int $id
 * @property int $change_request_id
 * @property string $original_name
 * @property string $stored_path
 * @property ImageFormat $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property int $width
 * @property int $height
 * @property int $position
 * @property ?CarbonImmutable $removed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class ChangeRequestAttachment extends Model
{
    /** @use HasFactory<ChangeRequestAttachmentFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mime' => ImageFormat::class,
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'position' => 'integer',
            'removed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): ChangeRequestAttachmentFactory
    {
        return ChangeRequestAttachmentFactory::new();
    }

    /**
     * @return BelongsTo<ChangeRequest, $this>
     */
    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class);
    }

    public function toData(): ChangeRequestAttachmentData
    {
        return new ChangeRequestAttachmentData(
            id: $this->id,
            changeRequestId: $this->change_request_id,
            originalName: $this->original_name,
            format: $this->mime,
            sizeBytes: $this->size_bytes,
            sha256: $this->sha256,
            width: $this->width,
            height: $this->height,
            position: $this->position,
            removedAt: $this->removed_at,
            createdAt: $this->created_at,
        );
    }
}
