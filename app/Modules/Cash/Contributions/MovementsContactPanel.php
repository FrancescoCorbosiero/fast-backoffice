<?php

declare(strict_types=1);

namespace App\Modules\Cash\Contributions;

use App\Modules\Cash\Filament\Widgets\ContactMovementsTable;
use App\Modules\Contacts\Contracts\ContactPanel;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;

/**
 * The contact page's "Movimenti" section.
 */
final class MovementsContactPanel implements ContactPanel
{
    public function key(): string
    {
        return 'cash-movements';
    }

    public function label(): string
    {
        return 'Movimenti di cassa';
    }

    public function sort(): int
    {
        return 50;
    }

    public function component(int $contactId): Component
    {
        return Livewire::make(ContactMovementsTable::class, ['contactId' => $contactId]);
    }
}
