<?php

declare(strict_types=1);

namespace App\Modules\Leads\Filament\Resources\Leads\Pages;

use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Leads\Actions\UpdateLead;
use App\Modules\Leads\Data\LeadInput;
use App\Modules\Leads\Filament\Resources\Leads\LeadResource;
use App\Modules\Leads\Models\Lead;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $data['estimated_value'] = $record instanceof Lead && $record->estimated_value_cents !== null ? Amounts::decimal($record->estimated_value_cents) : null;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Lead) {
            throw new LogicException('The page edits leads.');
        }

        app(UpdateLead::class)->update($record->id, LeadInput::fromArray($data));

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return LeadResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
