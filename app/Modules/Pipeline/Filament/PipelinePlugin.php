<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament;

use App\Modules\Pipeline\Filament\Resources\ChangeRequests\ChangeRequestResource;
use App\Modules\Pipeline\Filament\Resources\WebsitePipelines\WebsitePipelineResource;
use App\Modules\Pipeline\Filament\Widgets\PipelineOverviewWidget;
use App\Modules\Pipeline\Http\AttachmentsController;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Support\Facades\Route;

class PipelinePlugin implements Plugin
{
    public function getId(): string
    {
        return 'pipeline';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([ChangeRequestResource::class, WebsitePipelineResource::class])->widgets([PipelineOverviewWidget::class]);

        // An attachment, for the owner: behind the panel's own login and
        // middleware (docs/pipeline.md §10). Named filament.admin.pipeline.attachment.
        $panel->authenticatedRoutes(function (): void {
            Route::get('change-requests/{record}/attachments/{attachment}', [AttachmentsController::class, 'owner'])
                ->whereNumber(['record', 'attachment'])
                ->name('pipeline.attachment');
        });
    }

    public function boot(Panel $panel): void {}
}
