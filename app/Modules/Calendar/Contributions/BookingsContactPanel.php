<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Contributions;

use App\Modules\Calendar\Filament\Resources\Bookings\BookingResource;
use App\Modules\Calendar\Filament\Widgets\ContactBookingsTable;
use App\Modules\Contacts\Contracts\ContactPanel;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Icons\Heroicon;

final class BookingsContactPanel implements ContactPanel
{
    public function key(): string
    {
        return 'bookings';
    }

    public function label(): string
    {
        return 'Appuntamenti';
    }

    public function sort(): int
    {
        return 50;
    }

    public function component(int $contactId): Component
    {
        return Livewire::make(ContactBookingsTable::class, ['contactId' => $contactId]);
    }

    /**
     * Nothing to create by hand: bookings arrive from Cal.com when the
     * contact books, and the studio only reads them here. The section still
     * leads somewhere — its header and empty state open the calendar.
     */
    public function createAction(int $contactId): ?Action
    {
        return null;
    }

    /**
     * Where the owner goes to see every booking, this contact's included.
     * Built fresh on every call: an Action instance belongs to one Livewire
     * component.
     */
    public static function openCalendar(): Action
    {
        return Action::make('open-calendar')
            ->label('Apri il calendario')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->url(BookingResource::getUrl('index'));
    }
}
