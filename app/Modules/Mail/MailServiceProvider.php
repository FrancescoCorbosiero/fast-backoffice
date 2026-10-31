<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Mail\Contributions\CampaignsContactPanel;
use App\Modules\Mail\Filament\MailPlugin;
use App\Modules\Mail\Http\SesNotificationController;
use App\Modules\Mail\Http\UnsubscribeController;
use App\Modules\Mail\Jobs\ReconcileCampaigns;
use App\Modules\Mail\Jobs\StartScheduledCampaigns;
use App\Modules\Mail\Listeners\RepointMergedContact;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Settings\Queries\GetMailSettings;
use App\Support\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class MailServiceProvider extends ModuleServiceProvider
{
    /**
     * The rate limiter campaign sends go through (design.md §4.2, Mail).
     */
    public const string RATE_LIMITER = 'campaign-mail';

    protected function moduleName(): string
    {
        return 'Mail';
    }

    protected function plugin(): ?string
    {
        return MailPlugin::class;
    }

    public function register(): void
    {
        parent::register();

        $this->app->tag([CampaignsContactPanel::class], ContactPanel::TAG);
        $this->app->tag([StartScheduledCampaigns::class, ReconcileCampaigns::class], ScheduledJob::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(ContactsMerged::class, RepointMergedContact::class);

        RateLimiter::for(self::RATE_LIMITER, fn (): Limit => Limit::perSecond(max(1, $this->app->make(GetMailSettings::class)->get()->ratePerSecond)));

        // Signed requests, no session, no CSRF: SNS posts JSON; the unsubscribe
        // POST comes from mail clients (RFC 8058) and from the confirmation page.
        Route::post('/webhooks/ses', SesNotificationController::class)->name('webhooks.ses');
        Route::get('/unsubscribe/{token}', [UnsubscribeController::class, 'show'])->name('mail.unsubscribe.show');
        Route::post('/unsubscribe/{token}', [UnsubscribeController::class, 'confirm'])->name('mail.unsubscribe');
    }
}
