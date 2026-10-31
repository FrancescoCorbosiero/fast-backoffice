<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Reference;
use App\Modules\Invoicing\Enums\PecMessageKind;
use App\Modules\Invoicing\Enums\PecMessageOutcome;
use Carbon\CarbonImmutable;

final readonly class PecMessageData
{
    public function __construct(
        public int $id,
        public string $messageId,
        public ?CarbonImmutable $receivedAt,
        public ?string $from,
        public ?string $subject,
        public PecMessageKind $kind,
        public PecMessageOutcome $outcome,
        public ?string $note,
        public ?int $invoiceId,
        public ?int $supplierInvoiceId,
        public ?string $sdiIdentifier,
        public ?string $fileName,
        public ?int $rawFileId,
        public CarbonImmutable $processedAt,
    ) {}

    public function reference(): Reference
    {
        return Reference::to('pec_message', $this->id);
    }
}
