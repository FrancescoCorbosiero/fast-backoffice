<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament;

use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Modules\Invoicing\Filament\Resources\RecurringInvoices\RecurringInvoiceResource;
use App\Modules\Invoicing\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Modules\Invoicing\Filament\Widgets\InvoicingOverviewWidget;
use Filament\Contracts\Plugin;
use Filament\Panel;

class InvoicingPlugin implements Plugin
{
    public function getId(): string
    {
        return 'invoicing';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                InvoiceResource::class,
                PaymentRequestResource::class,
                RecurringInvoiceResource::class,
                SupplierInvoiceResource::class,
            ])
            ->widgets([InvoicingOverviewWidget::class]);
    }

    public function boot(Panel $panel): void {}
}
