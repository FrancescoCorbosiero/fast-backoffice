<?php

declare(strict_types=1);

namespace App\Modules\Pipeline;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Contacts\Events\ContactsMerged;
use App\Modules\Pipeline\Auth\ClientUserProvider;
use App\Modules\Pipeline\Contributions\ChangeRequestsContactPanel;
use App\Modules\Pipeline\Contributions\PipelineWebsitePanel;
use App\Modules\Pipeline\Filament\PipelinePlugin;
use App\Modules\Pipeline\Http\Portal\RequestsController;
use App\Modules\Pipeline\Http\Portal\SignInController;
use App\Modules\Pipeline\Integrations\CommandRunner;
use App\Modules\Pipeline\Integrations\SymfonyCommandRunner;
use App\Modules\Pipeline\Jobs\DeployApprovedRequests;
use App\Modules\Pipeline\Listeners\RepointMergedContact;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Policies\ChangeRequestPolicy;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Websites\Contracts\WebsitePanel;
use App\Support\ModuleServiceProvider;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

class PipelineServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Pipeline';
    }

    protected function plugin(): ?string
    {
        return PipelinePlugin::class;
    }

    public function register(): void
    {
        parent::register();

        $this->app->bind(CommandRunner::class, SymfonyCommandRunner::class);
        $this->app->tag([DeployApprovedRequests::class], ScheduledJob::TAG);
        $this->app->tag([ChangeRequestsContactPanel::class], ContactPanel::TAG);
        $this->app->tag([PipelineWebsitePanel::class], WebsitePanel::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(ChangeRequest::class, ChangeRequestPolicy::class);
        Auth::provider('pipeline-clients', fn (Application $app): ClientUserProvider => $app->make(ClientUserProvider::class));

        Event::listen(ContactsMerged::class, RepointMergedContact::class);

        $this->portalRoutes();
    }

    /**
     * The client portal: sessions and CSRF (the "web" group), the portal
     * guard, guests sent to the sign-in form. Route names only, so nothing
     * outside the module refers to it.
     */
    private function portalRoutes(): void
    {
        Authenticate::redirectUsing(fn (Request $request): string => $request->is('area-clienti*') ? route('portal.sign-in') : route('filament.admin.auth.login'));

        Route::middleware('web')->prefix('area-clienti')->name('portal.')->group(function (): void {
            Route::get('/accedi', [SignInController::class, 'show'])->name('sign-in');
            Route::post('/accedi', [SignInController::class, 'send'])->middleware('throttle:5,1')->name('sign-in.send');
            Route::get('/accedi/{token}', [SignInController::class, 'confirm'])->where('token', '[A-Za-z0-9]{48}')->name('sign-in.confirm');
            Route::post('/accedi/{token}', [SignInController::class, 'consume'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('sign-in.consume');

            Route::middleware('auth:portal')->group(function (): void {
                Route::post('/esci', [SignInController::class, 'signOut'])->name('sign-out');
                Route::get('/', [RequestsController::class, 'index'])->name('requests');
                Route::get('/richieste/nuova', [RequestsController::class, 'create'])->name('requests.create');
                Route::post('/richieste', [RequestsController::class, 'store'])->name('requests.store');
                Route::get('/richieste/{id}', [RequestsController::class, 'show'])->whereNumber('id')->name('requests.show');
                Route::post('/richieste/{id}/approva', [RequestsController::class, 'approve'])->whereNumber('id')->name('requests.approve');
                Route::post('/richieste/{id}/rifiuta', [RequestsController::class, 'reject'])->whereNumber('id')->name('requests.reject');
            });
        });
    }
}
