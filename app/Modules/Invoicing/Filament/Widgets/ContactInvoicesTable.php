<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Widgets;

use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Models\Invoice;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ContactInvoicesTable extends TableWidget
{
    public int $contactId = 0;

    protected static bool $isDiscovered = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Invoice::query()->where('contact_id', $this->contactId))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number_text')->label('Numero')->placeholder('bozza')->url(fn (Invoice $record): string => InvoiceResource::getUrl('view', ['record' => $record])),
                TextColumn::make('issued_on')->label('Data')->date('d/m/Y')->placeholder('—'),
                TextColumn::make('total_cents')->label('Totale')->alignEnd()->formatStateUsing(fn (Invoice $record): string => $record->toData()->total->format()),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())->color(fn (InvoiceStatus $state): string => $state->color()),
            ])
            ->emptyStateHeading('Nessuna fattura')
            ->paginated([10, 25]);
    }
}
