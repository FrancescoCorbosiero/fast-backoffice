<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Contributions;

use App\Modules\Calendar\Filament\Widgets\ContactBookingsTable;
use App\Modules\Contacts\Contracts\ContactPanel;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;

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
}
