<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\RecurringInvoices\Pages;

use App\Modules\Invoicing\Filament\Resources\RecurringInvoices\RecurringInvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecurringInvoices extends ListRecords
{
    protected static string $resource = RecurringInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
