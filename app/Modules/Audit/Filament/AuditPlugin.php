<?php

declare(strict_types=1);

namespace App\Modules\Audit\Filament;

use App\Modules\Audit\Filament\Resources\AuditEntries\AuditEntryResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

class AuditPlugin implements Plugin
{
    public function getId(): string
    {
        return 'audit';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([AuditEntryResource::class]);
    }

    public function boot(Panel $panel): void {}
}
