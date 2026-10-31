<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Integrations\Pec;

final readonly class OutgoingPecMessage
{
    public function __construct(
        public string $from,
        public string $to,
        public string $subject,
        public string $text,
        public string $attachmentName,
        public string $attachmentContents,
        public string $attachmentMimeType = 'application/xml',
    ) {}
}
