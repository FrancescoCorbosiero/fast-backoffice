<?php

declare(strict_types=1);

namespace App\Modules\Leads;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Leads\Contributions\LeadsContactPanel;
use App\Modules\Leads\Filament\LeadsPlugin;
use App\Modules\Leads\Listeners\RepointMergedContact;
use App\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class LeadsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Leads';
    }

    protected function plugin(): ?string
    {
        return LeadsPlugin::class;
    }

    public function register(): void
    {
        parent::register();

        $this->app->tag([LeadsContactPanel::class], ContactPanel::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(ContactsMerged::class, RepointMergedContact::class);
    }
}
