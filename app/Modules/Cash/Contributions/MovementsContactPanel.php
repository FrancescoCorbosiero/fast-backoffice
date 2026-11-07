<?php

declare(strict_types=1);

namespace App\Modules\Cash\Contributions;

use App\Modules\Cash\Filament\Resources\Movements\Pages\CreateMovement;
use App\Modules\Cash\Filament\Widgets\ContactMovementsTable;
use App\Modules\Contacts\Contracts\ContactPanel;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Icons\Heroicon;

/**
 * The contact page's "Movimenti di cassa" section.
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

    public function createAction(int $contactId): Action
    {
        return self::newMovement($contactId);
    }

    /**
     * The one way to record a movement for a contact by hand: the create
     * form, already knowing the counterparty. Built fresh on every call:
     * an Action instance belongs to one Livewire component.
     */
    public static function newMovement(int $contactId): Action
    {
        return Action::make('new-movement')
            ->label('Nuovo movimento')
            ->icon(Heroicon::OutlinedBanknotes)
            ->url(CreateMovement::prefilledUrl(['contact_id' => $contactId]));
    }
}
