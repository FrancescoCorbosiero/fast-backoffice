<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Contributions;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Quotes\Filament\Widgets\ContactQuotesTable;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;

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
}
