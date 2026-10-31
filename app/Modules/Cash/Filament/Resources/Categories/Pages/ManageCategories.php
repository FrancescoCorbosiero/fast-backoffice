<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\Categories\Pages;

use App\Modules\Cash\Filament\Resources\Categories\CategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCategories extends ManageRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
