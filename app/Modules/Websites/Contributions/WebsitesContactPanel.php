<?php

declare(strict_types=1);

namespace App\Modules\Websites\Contributions;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Websites\Filament\Resources\Websites\Pages\CreateWebsite;
use App\Modules\Websites\Filament\Widgets\ContactWebsitesTable;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Icons\Heroicon;

final class WebsitesContactPanel implements ContactPanel
{
    public function key(): string
    {
        return 'websites';
    }

    public function label(): string
    {
        return 'Siti';
    }

    public function sort(): int
    {
        return 30;
    }

    public function component(int $contactId): Component
    {
        return Livewire::make(ContactWebsitesTable::class, ['contactId' => $contactId]);
    }

    public function createAction(int $contactId): Action
    {
        return self::newWebsite($contactId);
    }

    /**
     * The one way to add a site for a contact: the create form, already
     * knowing the client. Built fresh on every call: an Action instance
     * belongs to one Livewire component.
     */
    public static function newWebsite(int $contactId): Action
    {
        return Action::make('new-website')
            ->label('Nuovo sito')
            ->icon(Heroicon::OutlinedPlus)
            ->url(CreateWebsite::prefilledUrl(['contact_id' => $contactId]));
    }
}
