<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Contributions;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages\CreateChangeRequest;
use App\Modules\Pipeline\Filament\Widgets\ContactChangeRequestsTable;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Icons\Heroicon;

/**
 * The contact page's "Richieste di modifica" section: what the client
 * asked for on their sites, what awaits a decision, and a request on
 * their behalf.
 */
final class ChangeRequestsContactPanel implements ContactPanel
{
    public function key(): string
    {
        return 'change-requests';
    }

    public function label(): string
    {
        return 'Richieste di modifica';
    }

    public function sort(): int
    {
        return 45;
    }

    public function component(int $contactId): Component
    {
        return Livewire::make(ContactChangeRequestsTable::class, ['contactId' => $contactId]);
    }

    public function createAction(int $contactId): Action
    {
        return self::newRequest($contactId);
    }

    /**
     * A request on the client's behalf: the form already knows the client
     * and lists only their sites with the pipeline ready. Built fresh on
     * every call: an Action instance belongs to one Livewire component.
     */
    public static function newRequest(int $contactId): Action
    {
        return Action::make('new-request')
            ->label('Nuova richiesta')
            ->icon(Heroicon::OutlinedSparkles)
            ->url(CreateChangeRequest::prefilledUrl(['contact_id' => $contactId]));
    }
}
