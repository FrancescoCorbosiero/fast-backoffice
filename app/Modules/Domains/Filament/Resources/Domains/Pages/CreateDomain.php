<?php

declare(strict_types=1);

namespace App\Modules\Domains\Filament\Resources\Domains\Pages;

use App\Modules\Domains\Actions\CreateDomain as CreateDomainAction;
use App\Modules\Domains\Data\DomainInput;
use App\Modules\Domains\Filament\Resources\Domains\DomainResource;
use App\Modules\Domains\Models\Domain;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDomain extends CreateRecord
{
    protected static string $resource = DomainResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $domain = app(CreateDomainAction::class)->create(DomainInput::fromArray($data));

        return Domain::query()->findOrFail($domain->id);
    }

    protected function getRedirectUrl(): string
    {
        return DomainResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
