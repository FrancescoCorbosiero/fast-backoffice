<?php

declare(strict_types=1);

namespace App\Modules\Calendar;

use App\Modules\Calendar\Contributions\BookingsContactPanel;
use App\Modules\Calendar\Filament\CalendarPlugin;
use App\Modules\Calendar\Http\CalComWebhookController;
use App\Modules\Calendar\Jobs\SyncBookings;
use App\Modules\Calendar\Listeners\RepointMergedContact;
use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

class CalendarServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Calendar';
    }

    protected function plugin(): ?string
    {
        return CalendarPlugin::class;
    }

    public function register(): void
    {
        parent::register();

        $this->app->tag([BookingsContactPanel::class], ContactPanel::TAG);
        $this->app->tag([SyncBookings::class], ScheduledJob::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(ContactsMerged::class, RepointMergedContact::class);

        // No session, no CSRF: the request is authenticated by its signature.
        Route::post('/webhooks/calcom', CalComWebhookController::class)->name('webhooks.calcom');
    }
}
