<?php

declare(strict_types=1);

namespace App\Modules\Quotes;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Quotes\Contributions\QuotesContactPanel;
use App\Modules\Quotes\Filament\QuotesPlugin;
use App\Modules\Quotes\Listeners\RepointMergedContact;
use App\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

class QuotesServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Quotes';
    }

    protected function plugin(): ?string
    {
        return QuotesPlugin::class;
    }

    public function register(): void
    {
        parent::register();

        $this->app->tag([QuotesContactPanel::class], ContactPanel::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(ContactsMerged::class, RepointMergedContact::class);
    }
}
