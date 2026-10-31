<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Contributions;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Invoicing\Filament\Widgets\ContactInvoicesTable;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;

final class InvoicesContactPanel implements ContactPanel
{
    public function key(): string
    {
        return 'invoices';
    }

    public function label(): string
    {
        return 'Fatture';
    }

    public function sort(): int
    {
        return 20;
    }

    public function component(int $contactId): Component
    {
        return Livewire::make(ContactInvoicesTable::class, ['contactId' => $contactId]);
    }
}
