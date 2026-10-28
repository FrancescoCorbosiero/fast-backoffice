<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament;

use App\Modules\Cash\Filament\Pages\CashFlowProjectionPage;
use App\Modules\Cash\Filament\Pages\StatementPage;
use App\Modules\Cash\Filament\Resources\Accounts\AccountResource;
use App\Modules\Cash\Filament\Resources\Categories\CategoryResource;
use App\Modules\Cash\Filament\Resources\Movements\MovementResource;
use App\Modules\Cash\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Modules\Cash\Filament\Widgets\ExpenseCategoriesWidget;
use App\Modules\Cash\Filament\Widgets\IncomeCategoriesWidget;
use App\Modules\Cash\Filament\Widgets\MonthlyTotalsChart;
use App\Modules\Cash\Filament\Widgets\YearToDateWidget;
use Filament\Contracts\Plugin;
use Filament\Panel;

class CashPlugin implements Plugin
{
    public function getId(): string
    {
        return 'cash';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                MovementResource::class,
                RecurringExpenseResource::class,
                AccountResource::class,
                CategoryResource::class,
            ])
            ->pages([
                CashFlowProjectionPage::class,
                StatementPage::class,
            ])
            ->widgets([
                YearToDateWidget::class,
                MonthlyTotalsChart::class,
                IncomeCategoriesWidget::class,
                ExpenseCategoriesWidget::class,
            ]);
    }

    public function boot(Panel $panel): void {}
}
