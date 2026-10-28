<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Events;

final readonly class ContactCreated
{
    public function __construct(public int $contactId) {}
}
