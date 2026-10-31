<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Events;

/**
 * Two contacts became one. Dispatched inside the merging transaction, so
 * every module that references contacts repoints $mergedContactId to
 * $contactId in the same transaction.
 */
final readonly class ContactsMerged
{
    public function __construct(
        public int $contactId,
        public int $mergedContactId,
    ) {}
}
