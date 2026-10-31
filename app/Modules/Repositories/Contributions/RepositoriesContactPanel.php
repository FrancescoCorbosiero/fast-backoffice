<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Contributions;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Repositories\Filament\Widgets\ContactRepositoriesTable;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;

final class RepositoriesContactPanel implements ContactPanel
{
    public function key(): string
    {
        return 'repositories';
    }

    public function label(): string
    {
        return 'Repository';
    }

    public function sort(): int
    {
        return 40;
    }

    public function component(int $contactId): Component
    {
        return Livewire::make(ContactRepositoriesTable::class, ['contactId' => $contactId]);
    }
}
