<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Events;

final readonly class ContactUpdated
{
    public function __construct(public int $contactId) {}
}
