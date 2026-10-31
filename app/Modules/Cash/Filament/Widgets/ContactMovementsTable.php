<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Widgets;

use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Models\Movement;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * A contact's movements, embedded in the contact page.
 */
class ContactMovementsTable extends TableWidget
{
    public int $contactId = 0;

    protected static bool $isDiscovered = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Movement::query()->with('account')->where('contact_id', $this->contactId))
            ->defaultSort('occurred_on', 'desc')
            ->columns([
                TextColumn::make('occurred_on')->label('Data')->date('d/m/Y'),
                TextColumn::make('description')->label('Descrizione')->wrap(),
                TextColumn::make('account.name')->label('Conto'),
                TextColumn::make('amount')->label('Importo')->alignEnd()
                    ->state(fn (Movement $record): string => Amounts::format($record->signedAmount()))
                    ->color(fn (Movement $record): string => $record->direction->color()),
            ])
            ->emptyStateHeading('Nessun movimento')
            ->paginated([10, 25]);
    }
}
