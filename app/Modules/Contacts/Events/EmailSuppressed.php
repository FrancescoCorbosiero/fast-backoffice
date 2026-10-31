<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Events;

/**
 * No more email to this contact: a bounce, a complaint, an unsubscribe or
 * the owner's decision. Dispatched once per suppression.
 */
final readonly class EmailSuppressed
{
    public function __construct(
        public int $contactId,
        public string $reason,
    ) {}
}
