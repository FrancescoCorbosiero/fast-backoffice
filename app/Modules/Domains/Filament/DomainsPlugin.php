<?php

declare(strict_types=1);

namespace App\Modules\Domains\Filament;

use App\Modules\Domains\Filament\Resources\Domains\DomainResource;
use App\Modules\Domains\Filament\Widgets\DomainsExpiringWidget;
use Filament\Contracts\Plugin;
use Filament\Panel;

class DomainsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'domains';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([DomainResource::class])->widgets([DomainsExpiringWidget::class]);
    }

    public function boot(Panel $panel): void {}
}
