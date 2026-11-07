<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Filament\Resources\Repositories\Pages;

use App\Modules\Repositories\Filament\Resources\Repositories\RepositoryActions;
use App\Modules\Repositories\Filament\Resources\Repositories\RepositoryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageRepositories extends ManageRecords
{
    protected static string $resource = RepositoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RepositoryActions::create(),
        ];
    }
}
