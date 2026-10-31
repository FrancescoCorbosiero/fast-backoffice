<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Queries;

use App\Modules\Invoicing\Data\SupplierInvoiceData;
use App\Modules\Invoicing\Models\SupplierInvoice;

final readonly class SupplierInvoices
{
    public function find(int $id): ?SupplierInvoiceData
    {
        return SupplierInvoice::query()->find($id)?->toData();
    }

    /**
     * @return list<SupplierInvoiceData> newest first
     */
    public function forContact(int $contactId, int $limit = 100): array
    {
        return array_values(SupplierInvoice::query()->where('contact_id', $contactId)->orderByDesc('issued_on')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (SupplierInvoice $s): SupplierInvoiceData => $s->toData())->all());
    }

    /**
     * Payables: what the studio still owes suppliers, by due date.
     *
     * @return list<SupplierInvoiceData>
     */
    public function payables(): array
    {
        return array_values(SupplierInvoice::query()->whereColumn('paid_cents', '<', 'total_cents')->orderBy('due_on')->orderBy('issued_on')->get()
            ->map(fn (SupplierInvoice $s): SupplierInvoiceData => $s->toData())->all());
    }
}
