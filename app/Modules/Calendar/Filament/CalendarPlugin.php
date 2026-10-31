<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Filament;

use App\Modules\Calendar\Filament\Resources\Bookings\BookingResource;
use App\Modules\Calendar\Filament\Widgets\UpcomingBookingsWidget;
use Filament\Contracts\Plugin;
use Filament\Panel;

class CalendarPlugin implements Plugin
{
    public function getId(): string
    {
        return 'calendar';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([BookingResource::class])->widgets([UpcomingBookingsWidget::class]);
    }

    public function boot(Panel $panel): void {}
}
