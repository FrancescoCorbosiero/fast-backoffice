<?php

declare(strict_types=1);

namespace App\Modules\Mail\Filament;

use App\Modules\Mail\Filament\Resources\Campaigns\CampaignResource;
use App\Modules\Mail\Filament\Resources\MailEvents\MailEventResource;
use App\Modules\Mail\Filament\Widgets\CampaignsOverviewWidget;
use Filament\Contracts\Plugin;
use Filament\Panel;

class MailPlugin implements Plugin
{
    public function getId(): string
    {
        return 'mail';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([CampaignResource::class, MailEventResource::class])->widgets([CampaignsOverviewWidget::class]);
    }

    public function boot(Panel $panel): void {}
}
