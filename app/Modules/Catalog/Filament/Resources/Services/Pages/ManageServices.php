<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Filament\Resources\Services\Pages;

use App\Modules\Catalog\Filament\Resources\Services\ServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageServices extends ManageRecords
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(fn (array $data): array => ServiceResource::toAttributes($data)),
        ];
    }
}
