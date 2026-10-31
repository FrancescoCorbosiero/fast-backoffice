<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Integrations\Pec;

final readonly class PecAttachment
{
    public function __construct(
        public string $name,
        public string $contents,
        public ?string $mimeType,
    ) {}
}
