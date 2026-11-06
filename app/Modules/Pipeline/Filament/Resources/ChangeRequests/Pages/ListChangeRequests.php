<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages;

use App\Modules\Pipeline\Filament\Resources\ChangeRequests\ChangeRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChangeRequests extends ListRecords
{
    protected static string $resource = ChangeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuova richiesta')];
    }
}
