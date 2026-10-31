<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\RecurringInvoices\Pages;

use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Invoicing\Filament\Resources\RecurringInvoices\RecurringInvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurringInvoice extends CreateRecord
{
    protected static string $resource = RecurringInvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = LinesSchema::toInput($data);
        $data['next_issue_on'] = $data['started_on'] ?? null;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return RecurringInvoiceResource::getUrl('index');
    }
}
