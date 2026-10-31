<?php

declare(strict_types=1);

namespace App\Modules\Websites\Filament\Widgets;

use App\Modules\Websites\Enums\WebsiteStatus;
use App\Modules\Websites\Filament\Resources\Websites\WebsiteResource;
use App\Modules\Websites\Models\Website;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ContactWebsitesTable extends TableWidget
{
    public int $contactId = 0;

    protected static bool $isDiscovered = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Website::query()->where('contact_id', $this->contactId))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nome')->url(fn (Website $record): string => WebsiteResource::getUrl('view', ['record' => $record])),
                TextColumn::make('host')->label('Host'),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (WebsiteStatus $state): string => $state->label())->color(fn (WebsiteStatus $state): string => $state->color()),
                IconColumn::make('is_up')->label('Online')->boolean()->placeholder('—'),
                TextColumn::make('hosting_renews_on')->label('Rinnovo hosting')->date('d/m/Y')->placeholder('—'),
            ])
            ->emptyStateHeading('Nessun sito')
            ->paginated([10, 25]);
    }
}
