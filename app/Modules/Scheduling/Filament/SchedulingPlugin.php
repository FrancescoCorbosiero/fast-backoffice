<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Filament;

use App\Modules\Scheduling\Filament\Pages\JobsPage;
use App\Modules\Scheduling\Filament\Resources\JobRuns\JobRunResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

class SchedulingPlugin implements Plugin
{
    public function getId(): string
    {
        return 'scheduling';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->pages([JobsPage::class])
            ->resources([JobRunResource::class]);
    }

    public function boot(Panel $panel): void {}
}
