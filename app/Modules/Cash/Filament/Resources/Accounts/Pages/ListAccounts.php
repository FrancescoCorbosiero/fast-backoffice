<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\Accounts\Pages;

use App\Modules\Cash\Filament\Resources\Accounts\AccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
