<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Contributions;

use App\Kernel\Reference;
use App\Modules\Cash\Contracts\AllocationPresenter;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Modules\Invoicing\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\PaymentRequest;
use App\Modules\Invoicing\Models\SupplierInvoice;

/**
 * How Invoicing's documents appear on the Cash screens.
 */
final class InvoiceAllocationPresenter implements AllocationPresenter
{
    public function supports(Reference $allocation): bool
    {
        return in_array($allocation->type, ['invoice', 'payment_request', 'supplier_invoice'], true);
    }

    public function label(Reference $allocation): string
    {
        $id = (int) $allocation->key;

        return match ($allocation->type) {
            'invoice' => Invoice::query()->find($id)?->toData()->title() ?? 'Fattura',
            'payment_request' => PaymentRequest::query()->find($id)?->toData()->title() ?? 'Richiesta di pagamento',
            'supplier_invoice' => SupplierInvoice::query()->find($id)?->toData()->title() ?? 'Fattura fornitore',
            default => $allocation->toString(),
        };
    }

    public function url(Reference $allocation): ?string
    {
        $id = (int) $allocation->key;

        return match ($allocation->type) {
            'invoice' => InvoiceResource::getUrl('view', ['record' => $id]),
            'payment_request' => PaymentRequestResource::getUrl('view', ['record' => $id]),
            'supplier_invoice' => SupplierInvoiceResource::getUrl('view', ['record' => $id]),
            default => null,
        };
    }
}
