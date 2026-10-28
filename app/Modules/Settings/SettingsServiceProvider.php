<?php

declare(strict_types=1);

namespace App\Modules\Settings;

use App\Modules\Settings\Filament\SettingsPlugin;
use App\Support\ModuleServiceProvider;

class SettingsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Settings';
    }

    protected function plugin(): ?string
    {
        return SettingsPlugin::class;
    }
}
