<?php

declare(strict_types=1);

namespace App\Modules\Mail\Filament\Resources\Campaigns\Pages;

use App\Modules\Mail\Filament\Resources\Campaigns\CampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCampaigns extends ListRecords
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
