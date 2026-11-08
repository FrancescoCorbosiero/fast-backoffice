<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Data;

use App\Modules\Pipeline\Enums\ImageFormat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * An image attached to a change request, as stored: metadata stripped,
 * dimensions and checksum of the stored file. The original name is the
 * client's, for display only.
 */
final readonly class ChangeRequestAttachmentData
{
    public const string WORKSPACE_DIR = 'pipeline-uploads';

    public function __construct(
        public int $id,
        public int $changeRequestId,
        public string $originalName,
        public ImageFormat $format,
        public int $sizeBytes,
        public string $sha256,
        public int $width,
        public int $height,
        public int $position,
        public ?CarbonImmutable $removedAt,
        public CarbonImmutable $createdAt,
    ) {}

    /**
     * The retention job took the file: the row stays for the audit trail.
     */
    public function isRemoved(): bool
    {
        return $this->removedAt !== null;
    }

    /**
     * Its name in the run's pipeline-uploads/ folder: the position, the
     * client's file name as an ASCII slug, the extension of the type it
     * really is ("1-foto-profilo.jpg"). Nothing the client wrote becomes
     * part of a path.
     */
    public function workspaceName(): string
    {
        $slug = rtrim(substr(Str::slug(pathinfo($this->originalName, PATHINFO_FILENAME)), 0, 60), '-');

        return $this->position.'-'.($slug === '' ? 'foto' : $slug).'.'.$this->format->extension();
    }

    /**
     * The path the agent sees, relative to the repository root.
     */
    public function workspacePath(): string
    {
        return self::WORKSPACE_DIR.'/'.$this->workspaceName();
    }

    public function sizeLabel(): string
    {
        if ($this->sizeBytes < 1024 * 1024) {
            return max(1, (int) round($this->sizeBytes / 1024)).' kB';
        }

        return number_format($this->sizeBytes / (1024 * 1024), 1, ',', '.').' MB';
    }
}
