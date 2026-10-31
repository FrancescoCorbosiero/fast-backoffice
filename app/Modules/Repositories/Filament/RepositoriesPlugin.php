<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Filament;

use App\Modules\Repositories\Filament\Resources\Repositories\RepositoryResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

class RepositoriesPlugin implements Plugin
{
    public function getId(): string
    {
        return 'repositories';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([RepositoryResource::class]);
    }

    public function boot(Panel $panel): void {}
}
