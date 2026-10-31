<?php

declare(strict_types=1);

namespace App\Modules\Cash;

use App\Modules\Cash\Contracts\CashFlowSchedule;
use App\Modules\Cash\Contributions\MovementsContactPanel;
use App\Modules\Cash\Contributions\RecurringExpensesSchedule;
use App\Modules\Cash\Filament\CashPlugin;
use App\Modules\Cash\Listeners\RepointMergedContact;
use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Contacts\Events\ContactsMerged;
use App\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class CashServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Cash';
    }

    protected function plugin(): ?string
    {
        return CashPlugin::class;
    }

    public function register(): void
    {
        parent::register();

        $this->app->tag([RecurringExpensesSchedule::class], CashFlowSchedule::TAG);
        $this->app->tag([MovementsContactPanel::class], ContactPanel::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(ContactsMerged::class, RepointMergedContact::class);
    }
}
