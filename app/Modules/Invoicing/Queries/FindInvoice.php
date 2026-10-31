<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Queries;

use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Models\Invoice;

final readonly class FindInvoice
{
    public function byId(int $id): ?InvoiceData
    {
        return Invoice::query()->find($id)?->toData();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, InvoiceData>
     */
    public function byIds(array $ids): array
    {
        $invoices = [];
        foreach (Invoice::query()->whereIn('id', $ids)->get() as $invoice) {
            $invoices[$invoice->id] = $invoice->toData();
        }

        return $invoices;
    }
}
