<?php

declare(strict_types=1);

namespace App\Modules\Invoicing;

use App\Modules\Cash\Contracts\AllocationPresenter;
use App\Modules\Cash\Contracts\CashFlowSchedule;
use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Invoicing\Console\RebuildPaidAmountsCommand;
use App\Modules\Invoicing\Contributions\InvoiceAllocationPresenter;
use App\Modules\Invoicing\Contributions\InvoicesContactPanel;
use App\Modules\Invoicing\Contributions\ReceivablesSchedule;
use App\Modules\Invoicing\Contributions\RecurringInvoicesSchedule;
use App\Modules\Invoicing\Filament\InvoicingPlugin;
use App\Modules\Invoicing\Jobs\IssueDueRecurringInvoices;
use App\Modules\Invoicing\Listeners\RepointMergedContact;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class InvoicingServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Invoicing';
    }

    protected function plugin(): ?string
    {
        return InvoicingPlugin::class;
    }

    public function register(): void
    {
        parent::register();

        $this->app->tag([ReceivablesSchedule::class, RecurringInvoicesSchedule::class], CashFlowSchedule::TAG);
        $this->app->tag([InvoiceAllocationPresenter::class], AllocationPresenter::TAG);
        $this->app->tag([InvoicesContactPanel::class], ContactPanel::TAG);
        $this->app->tag([IssueDueRecurringInvoices::class], ScheduledJob::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([RebuildPaidAmountsCommand::class]);
        Event::listen(ContactsMerged::class, RepointMergedContact::class);
    }
}
