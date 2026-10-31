<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Filament\Widgets;

use App\Modules\Quotes\Filament\Resources\Quotes\QuoteResource;
use App\Modules\Quotes\Models\Quote;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

class ContactQuotesTable extends TableWidget
{
    public int $contactId = 0;

    protected static bool $isDiscovered = false;

    public function table(Table $table): Table
    {
        $today = Date::today();

        return $table
            ->query(fn (): Builder => Quote::query()->where('contact_id', $this->contactId))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number_text')->label('Numero')->url(fn (Quote $record): string => QuoteResource::getUrl('view', ['record' => $record])),
                TextColumn::make('subject')->label('Oggetto')->placeholder('—')->limit(40),
                TextColumn::make('issued_on')->label('Data')->date('d/m/Y'),
                TextColumn::make('total_cents')->label('Totale')->alignEnd()->formatStateUsing(fn (Quote $record): string => $record->toData()->total->format()),
                TextColumn::make('status')->label('Stato')->badge()
                    ->formatStateUsing(fn (Quote $record): string => $record->toData()->statusOn($today)->label())
                    ->color(fn (Quote $record): string => $record->toData()->statusOn($today)->color()),
            ])
            ->emptyStateHeading('Nessun preventivo')
            ->paginated([10, 25]);
    }
}
