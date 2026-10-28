<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\RecurringExpenses\Pages;

use App\Modules\Cash\Filament\Amounts;
use App\Modules\Cash\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurringExpense extends CreateRecord
{
    protected static string $resource = RecurringExpenseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['amount_cents'] = Amounts::cents($data['amount'] ?? '0');
        $data['currency'] = 'EUR';
        $data['next_due_on'] = $data['started_on'] ?? null;
        unset($data['amount']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return RecurringExpenseResource::getUrl('index');
    }
}
