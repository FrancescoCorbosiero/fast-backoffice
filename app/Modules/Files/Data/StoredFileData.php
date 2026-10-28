<?php

declare(strict_types=1);

namespace App\Modules\Files\Data;

use App\Kernel\Reference;
use Carbon\CarbonImmutable;

/**
 * A stored file as other modules see it: where it belongs, what it is and
 * its checksum. The disk and path stay inside the Files module.
 */
final readonly class StoredFileData
{
    public function __construct(
        public int $id,
        public Reference $owner,
        public string $kind,
        public string $filename,
        public string $mimeType,
        public int $size,
        public string $sha256,
        public CarbonImmutable $createdAt,
    ) {}
}
