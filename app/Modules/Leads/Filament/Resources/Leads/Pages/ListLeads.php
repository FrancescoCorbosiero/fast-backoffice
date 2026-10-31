<?php

declare(strict_types=1);

namespace App\Modules\Leads\Filament\Resources\Leads\Pages;

use App\Modules\Leads\Filament\Resources\Leads\LeadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
