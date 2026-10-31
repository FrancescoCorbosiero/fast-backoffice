<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\PecMessages\Pages;

use App\Modules\Invoicing\Filament\Resources\PecMessages\PecMessageResource;
use App\Modules\Invoicing\Jobs\ReadPecInbox;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPecMessages extends ListRecords
{
    protected static string $resource = PecMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('read')->label('Leggi la casella ora')->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    $result = app(ReadPecInbox::class)->run();
                    $notification = Notification::make()->title($result->succeeded() ? 'Casella letta' : 'Lettura con avvisi')->body($result->output);
                    $result->succeeded() ? $notification->success()->send() : $notification->warning()->persistent()->send();
                }),
        ];
    }
}
