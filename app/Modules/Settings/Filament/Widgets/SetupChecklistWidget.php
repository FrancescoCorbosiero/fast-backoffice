<?php

declare(strict_types=1);

namespace App\Modules\Settings\Filament\Widgets;

use App\Modules\Settings\Filament\Pages\BusinessProfilePage;
use App\Modules\Settings\Filament\Pages\SdiSettingsPage;
use App\Modules\Settings\Queries\GetBusinessProfile;
use App\Modules\Settings\Queries\GetSdiSettings;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What the owner still has to set up before the first invoice: at the top
 * of the dashboard, each line a link to the screen that fixes it, gone
 * once nothing is missing.
 */
class SetupChecklistWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected ?string $heading = 'Da completare';

    protected ?string $description = 'Prima della prima fattura: ogni voce porta alla schermata che la sistema.';

    public static function canView(): bool
    {
        return self::missing() !== [];
    }

    protected function getStats(): array
    {
        return self::missing();
    }

    /**
     * @return list<Stat>
     */
    private static function missing(): array
    {
        $stats = [];

        if (! app(GetBusinessProfile::class)->get()->isComplete()) {
            $stats[] = Stat::make('Profilo aziendale', 'incompleto')
                ->description('Denominazione, partita IVA o codice fiscale e sede: il cedente di ogni fattura.')
                ->color('warning')
                ->url(BusinessProfilePage::getUrl());
        }

        if (app(GetSdiSettings::class)->get()->pecAddress === null) {
            $stats[] = Stat::make('PEC dell\'app', 'non impostata')
                ->description('La casella da cui partono le fatture verso SdI.')
                ->color('warning')
                ->url(SdiSettingsPage::getUrl());
        }

        if (! SdiSettingsPage::mailboxConfigured()) {
            $stats[] = Stat::make('Casella PEC sul server', 'non configurata')
                ->description('Variabili d\'ambiente PEC_*: senza, le fatture si inviano a mano con "Scarica XML".')
                ->color('gray')
                ->url(SdiSettingsPage::getUrl());
        }

        return $stats;
    }
}
