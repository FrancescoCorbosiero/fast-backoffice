<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\RecurringExpenses\Pages;

use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use Filament\Resources\Pages\EditRecord;

class EditRecurringExpense extends EditRecord
{
    protected static string $resource = RecurringExpenseResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['amount'] = Amounts::decimal($data['amount_cents'] ?? 0);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['amount_cents'] = Amounts::cents($data['amount'] ?? '0');
        unset($data['amount']);

        // A schedule that hasn't been logged yet follows its new first date.
        $record = $this->getRecord();
        if ($record->getAttribute('next_due_on') === $record->getAttribute('started_on') && isset($data['started_on'])) {
            $data['next_due_on'] = $data['started_on'];
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return RecurringExpenseResource::getUrl('index');
    }
}
