<?php

declare(strict_types=1);

namespace App\Modules\Mail\Events;

final readonly class RecipientBounced
{
    public function __construct(public int $recipientId, public int $contactId, public bool $permanent) {}
}
