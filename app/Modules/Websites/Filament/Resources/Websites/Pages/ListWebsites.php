<?php

declare(strict_types=1);

namespace App\Modules\Websites\Filament\Resources\Websites\Pages;

use App\Modules\Websites\Filament\Resources\Websites\WebsiteResource;
use App\Modules\Websites\Jobs\CheckUptime;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListWebsites extends ListRecords
{
    protected static string $resource = WebsiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('check')->label('Controlla ora')->icon(Heroicon::OutlinedSignal)
                ->action(function (): void {
                    $result = app(CheckUptime::class)->run();
                    $notification = Notification::make()->title($result->succeeded() ? 'Tutti online' : 'Qualche sito non risponde')->body($result->output);
                    $result->succeeded() ? $notification->success()->send() : $notification->danger()->persistent()->send();
                }),
            CreateAction::make(),
        ];
    }
}
