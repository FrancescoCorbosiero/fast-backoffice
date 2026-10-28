<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Contracts\Plugin;
use Illuminate\Support\ServiceProvider;

/**
 * What every module's service provider shares: its migrations, and the
 * Filament plugin through which it contributes resources, pages and
 * widgets to the panel (collected by the panel provider through the
 * "filament.plugins" container tag).
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    public const string PLUGIN_TAG = 'filament.plugins';

    abstract protected function moduleName(): string;

    /**
     * @return class-string<Plugin>|null
     */
    protected function plugin(): ?string
    {
        return null;
    }

    public function register(): void
    {
        $plugin = $this->plugin();

        if ($plugin !== null) {
            $this->app->tag([$plugin], self::PLUGIN_TAG);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(app_path('Modules/'.$this->moduleName().'/Database/Migrations'));
    }
}
