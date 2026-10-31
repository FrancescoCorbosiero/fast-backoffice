<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Filament\Widgets;

use App\Modules\Repositories\Enums\RepositoryProvider;
use App\Modules\Repositories\Models\Repository;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ContactRepositoriesTable extends TableWidget
{
    public int $contactId = 0;

    protected static bool $isDiscovered = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Repository::query()->where('contact_id', $this->contactId))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nome'),
                TextColumn::make('provider')->label('Servizio')->formatStateUsing(fn (RepositoryProvider $state): string => $state->label()),
                TextColumn::make('url')->label('Indirizzo')->url(fn (Repository $record): string => $record->url, true)->limit(50),
            ])
            ->emptyStateHeading('Nessun repository')
            ->paginated([10, 25]);
    }
}
