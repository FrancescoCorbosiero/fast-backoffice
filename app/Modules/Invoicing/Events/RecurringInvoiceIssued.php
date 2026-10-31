<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Events;

final readonly class RecurringInvoiceIssued
{
    public function __construct(public int $recurringInvoiceId, public int $invoiceId) {}
}
