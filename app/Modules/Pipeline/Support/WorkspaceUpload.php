<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Support;

/**
 * One photo for a run: its name in pipeline-uploads/ (the same one the
 * prompt lists, ChangeRequestAttachmentData::workspaceName()), where it
 * is stored, and the checksum the copy must match.
 */
final readonly class WorkspaceUpload
{
    public function __construct(
        public string $name,
        public string $storedPath,
        public string $sha256,
    ) {}
}
