<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament;

use App\Modules\Pipeline\Filament\Resources\ChangeRequests\ChangeRequestResource;
use App\Modules\Pipeline\Filament\Resources\WebsitePipelines\WebsitePipelineResource;
use App\Modules\Pipeline\Filament\Widgets\PipelineOverviewWidget;
use Filament\Contracts\Plugin;
use Filament\Panel;

class PipelinePlugin implements Plugin
{
    public function getId(): string
    {
        return 'pipeline';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([ChangeRequestResource::class, WebsitePipelineResource::class])->widgets([PipelineOverviewWidget::class]);
    }

    public function boot(Panel $panel): void {}
}
