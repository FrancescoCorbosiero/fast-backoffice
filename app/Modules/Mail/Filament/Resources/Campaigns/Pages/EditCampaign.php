<?php

declare(strict_types=1);

namespace App\Modules\Mail\Filament\Resources\Campaigns\Pages;

use App\Modules\Mail\Actions\SaveCampaign;
use App\Modules\Mail\Data\CampaignInput;
use App\Modules\Mail\Filament\Resources\Campaigns\CampaignResource;
use App\Modules\Mail\Models\Campaign;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Campaign) {
            throw new LogicException('The page edits campaigns.');
        }

        app(SaveCampaign::class)->update($record->id, CampaignInput::fromArray($data));

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return CampaignResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
