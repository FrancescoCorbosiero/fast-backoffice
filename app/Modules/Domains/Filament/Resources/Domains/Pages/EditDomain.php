<?php

declare(strict_types=1);

namespace App\Modules\Domains\Filament\Resources\Domains\Pages;

use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Domains\Actions\UpdateDomain;
use App\Modules\Domains\Data\DomainInput;
use App\Modules\Domains\Filament\Resources\Domains\DomainResource;
use App\Modules\Domains\Models\Domain;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class EditDomain extends EditRecord
{
    protected static string $resource = DomainResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $data['renewal_cost'] = $record instanceof Domain && $record->renewal_cost_cents !== null ? Amounts::decimal($record->renewal_cost_cents) : null;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Domain) {
            throw new LogicException('The page edits domains.');
        }

        app(UpdateDomain::class)->update($record->id, DomainInput::fromArray($data));

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return DomainResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
