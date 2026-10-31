<?php

declare(strict_types=1);

namespace App\Modules\Leads\Filament\Resources\Leads\Pages;

use App\Modules\Leads\Actions\CreateLead as CreateLeadAction;
use App\Modules\Leads\Data\LeadInput;
use App\Modules\Leads\Filament\Resources\Leads\LeadResource;
use App\Modules\Leads\Models\Lead;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $lead = app(CreateLeadAction::class)->create(LeadInput::fromArray($data));

        return Lead::query()->findOrFail($lead->id);
    }

    protected function getRedirectUrl(): string
    {
        return LeadResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
