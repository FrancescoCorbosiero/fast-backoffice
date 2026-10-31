<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\Accounts\Pages;

use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Filament\Resources\Accounts\AccountResource;
use Filament\Resources\Pages\EditRecord;

class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['opening_balance'] = Amounts::decimal($data['opening_balance_cents'] ?? 0);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['opening_balance_cents'] = Amounts::cents($data['opening_balance'] ?? '0');
        unset($data['opening_balance'], $data['movements_balance']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return AccountResource::getUrl('index');
    }
}
