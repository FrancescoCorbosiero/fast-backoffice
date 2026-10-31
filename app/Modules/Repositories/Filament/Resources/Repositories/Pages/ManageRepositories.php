<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Filament\Resources\Repositories\Pages;

use App\Modules\Repositories\Actions\SaveRepository;
use App\Modules\Repositories\Data\RepositoryInput;
use App\Modules\Repositories\Filament\Resources\Repositories\RepositoryResource;
use App\Modules\Repositories\Models\Repository;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRepositories extends ManageRecords
{
    protected static string $resource = RepositoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(function (array $data): Repository {
                $repository = app(SaveRepository::class)->create(RepositoryInput::fromArray($data));

                return Repository::query()->findOrFail($repository->id);
            }),
        ];
    }
}
