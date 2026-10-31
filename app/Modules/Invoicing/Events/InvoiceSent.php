<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Events;

use App\Modules\Invoicing\Enums\SendChannel;

final readonly class InvoiceSent
{
    public function __construct(public int $invoiceId, public SendChannel $channel) {}
}
