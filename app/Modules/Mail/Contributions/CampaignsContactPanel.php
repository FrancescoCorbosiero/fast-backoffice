<?php

declare(strict_types=1);

namespace App\Modules\Mail\Contributions;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Mail\Filament\Widgets\ContactCampaignsTable;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;

final class CampaignsContactPanel implements ContactPanel
{
    public function key(): string
    {
        return 'campaigns';
    }

    public function label(): string
    {
        return 'Campagne email';
    }

    public function sort(): int
    {
        return 60;
    }

    public function component(int $contactId): Component
    {
        return Livewire::make(ContactCampaignsTable::class, ['contactId' => $contactId]);
    }
}
