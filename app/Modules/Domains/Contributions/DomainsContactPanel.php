<?php

declare(strict_types=1);

namespace App\Modules\Domains\Contributions;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Domains\Filament\Widgets\ContactDomainsTable;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;

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
}
