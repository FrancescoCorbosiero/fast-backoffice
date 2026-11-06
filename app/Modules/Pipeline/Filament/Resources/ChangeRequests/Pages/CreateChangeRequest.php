<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages;

use App\Modules\Pipeline\Actions\SubmitChangeRequest;
use App\Modules\Pipeline\Data\ChangeRequestInput;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\ChangeRequestResource;
use App\Modules\Pipeline\Models\ChangeRequest;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateChangeRequest extends CreateRecord
{
    protected static string $resource = ChangeRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $request = app(SubmitChangeRequest::class)->submit(ChangeRequestInput::fromArray($data));

        return ChangeRequest::query()->findOrFail($request->id);
    }

    protected function getRedirectUrl(): string
    {
        return ChangeRequestResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
