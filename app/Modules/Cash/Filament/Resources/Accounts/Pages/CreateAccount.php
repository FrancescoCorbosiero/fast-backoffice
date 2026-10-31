<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\Accounts\Pages;

use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Filament\Resources\Accounts\AccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccount extends CreateRecord
{
    protected static string $resource = AccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['opening_balance_cents'] = Amounts::cents($data['opening_balance'] ?? '0');
        unset($data['opening_balance']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return AccountResource::getUrl('index');
    }
}
