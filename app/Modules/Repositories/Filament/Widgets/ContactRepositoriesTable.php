<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Filament\Widgets;

use App\Modules\Repositories\Contributions\RepositoriesContactPanel;
use App\Modules\Repositories\Enums\RepositoryProvider;
use App\Modules\Repositories\Models\Repository;
use Filament\Support\Icons\Heroicon;
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
            ->heading('Repository')
            ->headerActions([RepositoriesContactPanel::newRepository($this->contactId)])
            ->defaultSort('name')
            ->columns(self::columns())
            ->emptyStateIcon(Heroicon::OutlinedCodeBracket)
            ->emptyStateHeading('Nessun repository')
            ->emptyStateDescription('La pipeline lavora su un repository collegato a un sito.')
            ->emptyStateActions([RepositoriesContactPanel::newRepository($this->contactId)])
            ->paginated([10, 25]);
    }

    /**
     * The columns every repository table shows: shared with the website
     * page's widget so the two read the same.
     *
     * @return list<TextColumn>
     */
    public static function columns(): array
    {
        return [
            TextColumn::make('name')->label('Nome'),
            TextColumn::make('provider')->label('Servizio')->formatStateUsing(fn (RepositoryProvider $state): string => $state->label()),
            TextColumn::make('url')->label('Indirizzo')->url(fn (Repository $record): string => $record->url, true)->limit(50),
        ];
    }
}
