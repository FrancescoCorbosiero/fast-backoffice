<?php

declare(strict_types=1);

namespace App\Modules\Websites\Contributions;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Websites\Filament\Widgets\ContactWebsitesTable;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;

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
}
