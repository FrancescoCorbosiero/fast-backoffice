<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Resources\WebsitePipelines\Pages;

use App\Modules\Pipeline\Actions\LinkRepository;
use App\Modules\Pipeline\Enums\PipelineKind;
use App\Modules\Pipeline\Filament\Resources\WebsitePipelines\WebsitePipelineResource;
use App\Modules\Pipeline\Models\WebsitePipeline;
use App\Support\Shape;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWebsitePipelines extends ManageRecords
{
    protected static string $resource = WebsitePipelineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Collega repository')->modalHeading('Collega un repository')->using(function (array $data): WebsitePipeline {
                $kind = PipelineKind::tryFrom(Shape::string($data, 'kind', PipelineKind::StaticSite->value)) ?? PipelineKind::StaticSite;
                $pipeline = app(LinkRepository::class)->link(Shape::int($data, 'website_id'), Shape::int($data, 'repository_id'), $kind);

                return WebsitePipeline::query()->findOrFail($pipeline->id);
            }),
        ];
    }
}
