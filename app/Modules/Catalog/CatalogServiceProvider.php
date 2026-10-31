<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\Catalog\Filament\CatalogPlugin;
use App\Support\ModuleServiceProvider;

class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Catalog';
    }

    protected function plugin(): ?string
    {
        return CatalogPlugin::class;
    }
}
