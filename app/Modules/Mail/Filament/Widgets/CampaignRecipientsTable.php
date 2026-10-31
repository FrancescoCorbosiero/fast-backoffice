<?php

declare(strict_types=1);

namespace App\Modules\Mail\Filament\Widgets;

use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Models\CampaignRecipient;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class CampaignRecipientsTable extends TableWidget
{
    public int $campaignId = 0;

    protected static bool $isDiscovered = false;

    public function table(Table $table): Table
    {
        $statuses = [];
        foreach (RecipientStatus::cases() as $status) {
            $statuses[$status->value] = $status->label();
        }

        return $table
            ->query(fn (): Builder => CampaignRecipient::query()->where('campaign_id', $this->campaignId))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (RecipientStatus $state): string => $state->label())->color(fn (RecipientStatus $state): string => $state->color()),
                TextColumn::make('attempts')->label('Tentativi')->alignEnd(),
                TextColumn::make('sent_at')->label('Inviata')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('last_error')->label('Note')->limit(60)->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->label('Stato')->options($statuses)])
            ->paginated([25, 50]);
    }
}
