<?php

declare(strict_types=1);

namespace App\Modules\Websites\Filament\Resources\Websites\Pages;

use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Websites\Actions\UpdateWebsite;
use App\Modules\Websites\Data\WebsiteInput;
use App\Modules\Websites\Filament\Resources\Websites\WebsiteResource;
use App\Modules\Websites\Models\Website;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class EditWebsite extends EditRecord
{
    protected static string $resource = WebsiteResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $data['hosting_cost'] = $record instanceof Website && $record->hosting_cost_cents !== null ? Amounts::decimal($record->hosting_cost_cents) : null;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Website) {
            throw new LogicException('The page edits websites.');
        }

        app(UpdateWebsite::class)->update($record->id, WebsiteInput::fromArray($data));

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return WebsiteResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
