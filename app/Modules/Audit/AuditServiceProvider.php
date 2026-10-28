<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\Audit\Filament\AuditPlugin;
use App\Support\ModuleServiceProvider;

class AuditServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Audit';
    }

    protected function plugin(): ?string
    {
        return AuditPlugin::class;
    }
}
