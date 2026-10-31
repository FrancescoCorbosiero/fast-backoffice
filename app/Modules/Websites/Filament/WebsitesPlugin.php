<?php

declare(strict_types=1);

namespace App\Modules\Websites\Filament;

use App\Modules\Websites\Filament\Resources\Websites\WebsiteResource;
use App\Modules\Websites\Filament\Widgets\WebsitesStatusWidget;
use Filament\Contracts\Plugin;
use Filament\Panel;

class WebsitesPlugin implements Plugin
{
    public function getId(): string
    {
        return 'websites';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([WebsiteResource::class])->widgets([WebsitesStatusWidget::class]);
    }

    public function boot(Panel $panel): void {}
}
