<?php

declare(strict_types=1);

namespace App\Modules\Domains\Filament\Resources\Domains\Pages;

use App\Modules\Domains\Filament\Resources\Domains\DomainResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDomains extends ListRecords
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
