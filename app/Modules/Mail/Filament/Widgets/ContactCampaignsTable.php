<?php

declare(strict_types=1);

namespace App\Modules\Mail\Filament\Widgets;

use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Filament\Resources\Campaigns\CampaignResource;
use App\Modules\Mail\Models\CampaignRecipient;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ContactCampaignsTable extends TableWidget
{
    public int $contactId = 0;

    protected static bool $isDiscovered = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CampaignRecipient::query()->with('campaign')->where('contact_id', $this->contactId))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('campaign.name')->label('Campagna')->url(fn (CampaignRecipient $record): string => CampaignResource::getUrl('view', ['record' => $record->campaign_id])),
                TextColumn::make('sent_at')->label('Inviata')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (RecipientStatus $state): string => $state->label())->color(fn (RecipientStatus $state): string => $state->color()),
                TextColumn::make('unsubscribed_at')->label('Disiscritto')->dateTime('d/m/Y')->placeholder('—'),
            ])
            ->emptyStateHeading('Nessuna campagna ricevuta')
            ->paginated([10, 25]);
    }
}
