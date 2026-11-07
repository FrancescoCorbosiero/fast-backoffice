<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Resources\WebsitePipelines\Pages;

use App\Modules\Pipeline\Filament\Resources\WebsitePipelines\PipelineActions;
use App\Modules\Pipeline\Filament\Resources\WebsitePipelines\WebsitePipelineResource;
use Filament\Resources\Pages\ManageRecords;

class ManageWebsitePipelines extends ManageRecords
{
    protected static string $resource = WebsitePipelineResource::class;

    protected function getHeaderActions(): array
    {
        return [PipelineActions::link(name: 'create')];
    }
}
