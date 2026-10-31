<?php

declare(strict_types=1);

namespace App\Modules\Repositories;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Repositories\Contributions\RepositoriesContactPanel;
use App\Modules\Repositories\Filament\RepositoriesPlugin;
use App\Modules\Repositories\Listeners\RepointMergedContact;
use App\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class RepositoriesServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Repositories';
    }

    protected function plugin(): ?string
    {
        return RepositoriesPlugin::class;
    }

    public function register(): void
    {
        parent::register();

        $this->app->tag([RepositoriesContactPanel::class], ContactPanel::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(ContactsMerged::class, RepointMergedContact::class);
    }
}
