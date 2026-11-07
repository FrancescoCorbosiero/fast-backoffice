<?php

declare(strict_types=1);

namespace App\Modules\Domains\Contributions;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Domains\Filament\Resources\Domains\Pages\CreateDomain;
use App\Modules\Domains\Filament\Widgets\ContactDomainsTable;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Icons\Heroicon;

final class DomainsContactPanel implements ContactPanel
{
    public function key(): string
    {
        return 'domains';
    }

    public function label(): string
    {
        return 'Domini';
    }

    public function sort(): int
    {
        return 35;
    }

    public function component(int $contactId): Component
    {
        return Livewire::make(ContactDomainsTable::class, ['contactId' => $contactId]);
    }

    public function createAction(int $contactId): Action
    {
        return self::newDomain($contactId);
    }

    /**
     * The one way to add a domain for a contact: the create form, already
     * knowing the client (and the site, when reached from one). Built
     * fresh on every call: an Action instance belongs to one Livewire
     * component.
     */
    public static function newDomain(int $contactId, ?int $websiteId = null): Action
    {
        return Action::make('new-domain')
            ->label('Nuovo dominio')
            ->icon(Heroicon::OutlinedGlobeEuropeAfrica)
            ->url(CreateDomain::prefilledUrl(['contact_id' => $contactId, 'website_id' => $websiteId]));
    }
}
