<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Events;

final readonly class PecMessageNeedsReview
{
    public function __construct(public int $pecMessageId, public string $note) {}
}
