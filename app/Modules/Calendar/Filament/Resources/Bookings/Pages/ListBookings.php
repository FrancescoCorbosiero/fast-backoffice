<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Filament\Resources\Bookings\Pages;

use App\Modules\Calendar\Filament\Resources\Bookings\BookingResource;
use App\Modules\Calendar\Integrations\CalComClient;
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
        $configured = app(CalComClient::class)->isConfigured();

        return [
            Action::make('book')->label('Prenota su Cal.com')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->url(BookingResource::calComAppUrl())->openUrlInNewTab(),
            Action::make('sync')->label('Sincronizza ora')->icon(Heroicon::OutlinedArrowPath)
                ->disabled(! $configured)
                ->tooltip($configured ? null : 'Cal.com non configurato (CALCOM_API_KEY)')
                ->action(function (): void {
                    $result = app(SyncBookings::class)->run();
                    $notification = Notification::make()->title($result->succeeded() ? 'Calendario aggiornato' : 'Sincronizzazione fallita')->body($result->output);
                    $result->succeeded() ? $notification->success()->send() : $notification->danger()->persistent()->send();
                }),
        ];
    }
}
