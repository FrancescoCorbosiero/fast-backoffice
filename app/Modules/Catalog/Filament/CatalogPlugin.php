<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Filament;

use App\Modules\Catalog\Filament\Resources\Services\ServiceResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

class CatalogPlugin implements Plugin
{
    public function getId(): string
    {
        return 'catalog';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([ServiceResource::class]);
    }

    public function boot(Panel $panel): void {}
}
