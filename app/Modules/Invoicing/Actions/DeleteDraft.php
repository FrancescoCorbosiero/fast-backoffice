<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final readonly class DeleteDraft
{
    public function __construct(private ConnectionInterface $db) {}

    public function delete(int $invoiceId): void
    {
        $this->db->transaction(function () use ($invoiceId): void {
            $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);

            if ($invoice === null) {
                return;
            }

            if ($invoice->status->isIssued()) {
                throw new LogicException("Invoice {$invoiceId} is issued: issued documents are corrected by credit notes, never deleted.");
            }

            $invoice->delete();
        });
    }
}
