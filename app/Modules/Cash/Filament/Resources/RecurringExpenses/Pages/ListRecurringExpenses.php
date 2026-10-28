<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\RecurringExpenses\Pages;

use App\Modules\Cash\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecurringExpenses extends ListRecords
{
    protected static string $resource = RecurringExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
