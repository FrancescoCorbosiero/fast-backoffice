<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Events;

final readonly class QuoteDeclined
{
    public function __construct(public int $quoteId, public ?string $reason) {}
}
