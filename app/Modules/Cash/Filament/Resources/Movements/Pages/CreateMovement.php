<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\Movements\Pages;

use App\Modules\Cash\Actions\RecordMovement;
use App\Modules\Cash\Data\MovementInput;
use App\Modules\Cash\Filament\Resources\Movements\MovementResource;
use App\Modules\Cash\Models\Movement;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMovement extends CreateRecord
{
    protected static string $resource = MovementResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $movement = app(RecordMovement::class)->record(MovementInput::fromArray($data));

        return Movement::query()->findOrFail($movement->id);
    }

    protected function getRedirectUrl(): string
    {
        return MovementResource::getUrl('index');
    }
}
