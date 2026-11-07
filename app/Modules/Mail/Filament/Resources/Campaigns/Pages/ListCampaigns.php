<?php

declare(strict_types=1);

namespace App\Modules\Mail\Filament\Resources\Campaigns\Pages;

use App\Modules\Mail\Filament\Resources\Campaigns\CampaignResource;
use App\Modules\Mail\Filament\Resources\MailEvents\MailEventResource;
use App\Modules\Mail\Models\MailEvent;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ListCampaigns extends ListRecords
{
    protected static string $resource = CampaignResource::class;

    /**
     * At a glance: are SES notifications flowing? The last one received,
     * or that none ever came.
     */
    public function getSubheading(): string|Htmlable|null
    {
        $last = MailEvent::query()->orderByDesc('id')->first();

        return $last === null
            ? 'Nessun evento SES ricevuto finora.'
            : 'Ultimo evento SES: '.($last->event_type ?? 'notifica').' '.$last->received_at->isoFormat('D MMM YYYY HH:mm').' ('.(MailEventResource::OUTCOMES[$last->outcome] ?? $last->outcome).').';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('events')->label('Eventi SES')->icon(Heroicon::OutlinedSignal)->color('gray')->url(MailEventResource::getUrl('index')),
            CreateAction::make(),
        ];
    }
}
