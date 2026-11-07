<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Widgets;

use App\Modules\Cash\Data\AccountData;
use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Filament\Pages\CashFlowProjectionPage;
use App\Modules\Cash\Filament\Pages\StatementPage;
use App\Modules\Cash\Queries\Accounts;
use App\Modules\Cash\Queries\CashFlowProjection;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The owner's first question of the day: how much is on each account right
 * now, and what the next three months look like. Each figure opens the
 * screen it summarises.
 */
class AccountBalancesWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $today = CarbonImmutable::today();
        $stats = array_map(
            fn (AccountData $account): Stat => Stat::make($account->name, Amounts::format($account->balance))
                ->description($account->type->label())
                ->color($account->balance->isNegative() ? 'danger' : null)
                ->url(StatementPage::getUrl(['account' => $account->id])),
            app(Accounts::class)->all(activeOnly: true),
        );

        $net = app(CashFlowProjection::class)->project($today, 3)->net();
        $stats[] = Stat::make('Prossimi 3 mesi', Amounts::format($net))
            ->description('entrate meno uscite attese')
            ->color($net->isNegative() ? 'danger' : 'success')
            ->url(CashFlowProjectionPage::getUrl(['months' => 3]));

        return $stats;
    }
}
