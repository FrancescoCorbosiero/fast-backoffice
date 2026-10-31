<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Filament\Resources\Bookings;

use App\Modules\Calendar\Enums\BookingStatus;
use App\Modules\Calendar\Filament\Resources\Bookings\Pages\ListBookings;
use App\Modules\Calendar\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Modules\Calendar\Models\Booking;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use UnitEnum;

/**
 * Bookings from Cal.com: read-only here, Cal.com is where they change.
 */
class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Crescita';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'appuntamento';

    protected static ?string $pluralModelLabel = 'Appuntamenti';

    protected static ?string $slug = 'bookings';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('starts_at')->label('Quando')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('title')->label('Titolo')->weight('bold')->searchable(),
                TextColumn::make('attendee_name')->label('Con')->placeholder('—')->searchable()->description(fn (Booking $record): ?string => $record->attendee_email),
                TextColumn::make('event_type')->label('Tipo')->placeholder('—'),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (BookingStatus $state): string => $state->label())->color(fn (BookingStatus $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(BookingStatus::options()),
                Filter::make('upcoming')->label('Prossimi')->default()
                    ->query(fn (Builder $query): Builder => $query->where('starts_at', '>=', Date::now())->whereIn('status', [BookingStatus::Accepted->value, BookingStatus::Pending->value])),
            ])
            ->recordActions([ViewAction::make()])
            ->paginated([25, 50]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookings::route('/'),
            'view' => ViewBooking::route('/{record}'),
        ];
    }
}
