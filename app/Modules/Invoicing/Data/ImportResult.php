<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Reference;

/**
 * What importing one FatturaPA file did.
 */
final readonly class ImportResult
{
    public function __construct(
        public string $fileName,
        public string $status,
        public ?string $direction,
        public ?Reference $reference,
        public ?string $reason = null,
    ) {}

    public static function imported(string $fileName, string $direction, Reference $reference): self
    {
        return new self($fileName, 'imported', $direction, $reference);
    }

    public static function skipped(string $fileName, string $direction, Reference $reference, string $reason): self
    {
        return new self($fileName, 'skipped', $direction, $reference, $reason);
    }

    public static function failed(string $fileName, string $reason): self
    {
        return new self($fileName, 'failed', null, null, $reason);
    }

    public function isImported(): bool
    {
        return $this->status === 'imported';
    }
}
