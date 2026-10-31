<?php

declare(strict_types=1);

namespace App\Modules\Leads\Filament;

use App\Modules\Leads\Filament\Resources\Leads\LeadResource;
use App\Modules\Leads\Filament\Widgets\LeadsPipelineWidget;
use Filament\Contracts\Plugin;
use Filament\Panel;

class LeadsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'leads';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([LeadResource::class])->widgets([LeadsPipelineWidget::class]);
    }

    public function boot(Panel $panel): void {}
}
