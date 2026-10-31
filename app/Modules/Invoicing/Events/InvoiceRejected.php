<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Events;

final readonly class InvoiceRejected
{
    /**
     * @param  list<array{code: string, description: string, hint: ?string}>  $errors
     */
    public function __construct(public int $invoiceId, public array $errors) {}
}
