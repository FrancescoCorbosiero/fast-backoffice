<?php

declare(strict_types=1);

namespace App\Modules\Domains;

use App\Modules\Cash\Contracts\AllocationPresenter;
use App\Modules\Cash\Contracts\CashFlowSchedule;
use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Domains\Contributions\DomainAllocationPresenter;
use App\Modules\Domains\Contributions\DomainRenewalsSchedule;
use App\Modules\Domains\Contributions\DomainsContactPanel;
use App\Modules\Domains\Filament\DomainsPlugin;
use App\Modules\Domains\Listeners\RepointMergedContact;
use App\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class DomainsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Domains';
    }

    protected function plugin(): ?string
    {
        return DomainsPlugin::class;
    }

    public function register(): void
    {
        parent::register();

        $this->app->tag([DomainRenewalsSchedule::class], CashFlowSchedule::TAG);
        $this->app->tag([DomainAllocationPresenter::class], AllocationPresenter::TAG);
        $this->app->tag([DomainsContactPanel::class], ContactPanel::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(ContactsMerged::class, RepointMergedContact::class);
    }
}
