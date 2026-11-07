<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Contributions;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Quotes\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Modules\Quotes\Filament\Widgets\ContactQuotesTable;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Icons\Heroicon;

final class QuotesContactPanel implements ContactPanel
{
    public function key(): string
    {
        return 'quotes';
    }

    public function label(): string
    {
        return 'Preventivi';
    }

    public function sort(): int
    {
        return 15;
    }

    public function component(int $contactId): Component
    {
        return Livewire::make(ContactQuotesTable::class, ['contactId' => $contactId]);
    }

    public function createAction(int $contactId): Action
    {
        return self::newQuote($contactId);
    }

    /**
     * The one way to write a quote for a contact: the create form, already
     * knowing the client. Built fresh on every call: an Action instance
     * belongs to one Livewire component.
     */
    public static function newQuote(int $contactId): Action
    {
        return Action::make('new-quote')->label('Nuovo preventivo')->icon(Heroicon::OutlinedDocumentText)
            ->url(CreateQuote::prefilledUrl(['contact_id' => $contactId]));
    }
}
