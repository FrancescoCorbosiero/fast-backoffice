<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Filament\Resources\Bookings\Pages;

use App\Modules\Calendar\Filament\Resources\Bookings\BookingResource;
use App\Modules\Calendar\Jobs\SyncBookings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')->label('Sincronizza ora')->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    $result = app(SyncBookings::class)->run();
                    $notification = Notification::make()->title($result->succeeded() ? 'Calendario aggiornato' : 'Sincronizzazione fallita')->body($result->output);
                    $result->succeeded() ? $notification->success()->send() : $notification->danger()->persistent()->send();
                }),
        ];
    }
}
