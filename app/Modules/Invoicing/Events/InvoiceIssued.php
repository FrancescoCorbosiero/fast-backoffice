<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Events;

final readonly class InvoiceIssued
{
    public function __construct(public int $id) {}
}
