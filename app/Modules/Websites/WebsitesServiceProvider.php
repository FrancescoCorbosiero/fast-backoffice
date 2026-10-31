<?php

declare(strict_types=1);

namespace App\Modules\Websites;

use App\Modules\Cash\Contracts\AllocationPresenter;
use App\Modules\Cash\Contracts\CashFlowSchedule;
use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Websites\Contributions\HostingAllocationPresenter;
use App\Modules\Websites\Contributions\HostingRenewalsSchedule;
use App\Modules\Websites\Contributions\WebsitesContactPanel;
use App\Modules\Websites\Filament\WebsitesPlugin;
use App\Modules\Websites\Jobs\CheckUptime;
use App\Modules\Websites\Listeners\RepointMergedContact;
use App\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class WebsitesServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Websites';
    }

    protected function plugin(): ?string
    {
        return WebsitesPlugin::class;
    }

    public function register(): void
    {
        parent::register();

        $this->app->tag([HostingRenewalsSchedule::class], CashFlowSchedule::TAG);
        $this->app->tag([HostingAllocationPresenter::class], AllocationPresenter::TAG);
        $this->app->tag([WebsitesContactPanel::class], ContactPanel::TAG);
        $this->app->tag([CheckUptime::class], ScheduledJob::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(ContactsMerged::class, RepointMergedContact::class);
    }
}
