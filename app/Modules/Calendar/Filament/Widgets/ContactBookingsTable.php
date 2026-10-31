<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Filament\Widgets;

use App\Modules\Calendar\Enums\BookingStatus;
use App\Modules\Calendar\Filament\Resources\Bookings\BookingResource;
use App\Modules\Calendar\Models\Booking;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ContactBookingsTable extends TableWidget
{
    public int $contactId = 0;

    protected static bool $isDiscovered = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Booking::query()->where('contact_id', $this->contactId))
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('starts_at')->label('Quando')->dateTime('d/m/Y H:i')->url(fn (Booking $record): string => BookingResource::getUrl('view', ['record' => $record])),
                TextColumn::make('title')->label('Titolo'),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (BookingStatus $state): string => $state->label())->color(fn (BookingStatus $state): string => $state->color()),
            ])
            ->emptyStateHeading('Nessun appuntamento')
            ->paginated([10, 25]);
    }
}
