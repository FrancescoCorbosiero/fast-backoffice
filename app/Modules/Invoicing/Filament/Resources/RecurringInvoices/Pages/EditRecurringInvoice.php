<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\RecurringInvoices\Pages;

use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Invoicing\Filament\Resources\RecurringInvoices\RecurringInvoiceResource;
use App\Modules\Invoicing\Models\RecurringInvoice;
use Filament\Resources\Pages\EditRecord;

class EditRecurringInvoice extends EditRecord
{
    protected static string $resource = RecurringInvoiceResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $data['lines'] = $record instanceof RecurringInvoice ? LinesSchema::toForm($record->lineInputs()) : [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = LinesSchema::toInput($data);
        $record = $this->getRecord();

        // A schedule that hasn't issued yet follows its new first date.
        if ($record instanceof RecurringInvoice && $record->last_issued_on === null && isset($data['started_on'])) {
            $data['next_issue_on'] = $data['started_on'];
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return RecurringInvoiceResource::getUrl('index');
    }
}
