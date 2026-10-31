<?php

declare(strict_types=1);

namespace App\Modules\Settings\Filament;

use App\Modules\Settings\Filament\Pages\BusinessProfilePage;
use App\Modules\Settings\Filament\Pages\InvoiceDefaultsPage;
use App\Modules\Settings\Filament\Pages\NumberingSeriesPage;
use App\Modules\Settings\Filament\Pages\SdiSettingsPage;
use Filament\Contracts\Plugin;
use Filament\Panel;

class SettingsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'settings';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([
            BusinessProfilePage::class,
            InvoiceDefaultsPage::class,
            NumberingSeriesPage::class,
            SdiSettingsPage::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
