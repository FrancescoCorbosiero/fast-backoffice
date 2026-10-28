<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Scheduling\JobRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Arch');

/**
 * The owner, signed in to the panel with two-factor authentication set up.
 */
function signInAsOwner(): User
{
    $owner = User::factory()->create();
    $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $owner;
}

/**
 * Makes the Scheduling module see these jobs instead of the app's own.
 *
 * @param  list<ScheduledJob>  $jobs
 */
function registerJobs(array $jobs): void
{
    app()->instance(JobRegistry::class, new JobRegistry($jobs));
}
