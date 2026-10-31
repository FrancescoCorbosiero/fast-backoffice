<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Events;

use App\Modules\Quotes\Enums\AcceptanceOutcome;

final readonly class QuoteAccepted
{
    public function __construct(
        public int $quoteId,
        public AcceptanceOutcome $outcome,
        public ?int $invoiceId,
        public ?int $paymentRequestId,
    ) {}
}
