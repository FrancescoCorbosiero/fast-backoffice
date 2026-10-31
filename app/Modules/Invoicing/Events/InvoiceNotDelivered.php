<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Events;

/**
 * SdI could not reach the customer: the invoice is issued and waits in
 * their area on the Agenzia's portal. Tell them, with the courtesy PDF.
 */
final readonly class InvoiceNotDelivered
{
    public function __construct(public int $invoiceId, public ?string $sdiIdentifier) {}
}
