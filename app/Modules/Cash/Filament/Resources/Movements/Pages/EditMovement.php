<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\Movements\Pages;

use App\Modules\Cash\Actions\DeleteMovement;
use App\Modules\Cash\Actions\UpdateMovement;
use App\Modules\Cash\Data\MovementInput;
use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Filament\Resources\Movements\MovementResource;
use App\Modules\Cash\Models\Movement;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class EditMovement extends EditRecord
{
    protected static string $resource = MovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('delete')
                ->label('Elimina')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (): void {
                    app(DeleteMovement::class)->delete($this->movement()->id);
                    $this->redirect(MovementResource::getUrl('index'));
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['amount'] = Amounts::decimal($data['amount_cents'] ?? 0);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Movement) {
            throw new LogicException('The movement form edits movements.');
        }

        app(UpdateMovement::class)->update($record->id, MovementInput::fromArray($data));

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return MovementResource::getUrl('index');
    }

    private function movement(): Movement
    {
        $record = $this->getRecord();

        if (! $record instanceof Movement) {
            throw new LogicException('The movement form edits movements.');
        }

        return $record;
    }
}
