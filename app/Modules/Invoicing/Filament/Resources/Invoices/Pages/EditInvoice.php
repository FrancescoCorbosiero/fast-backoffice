<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Pages;

use App\Modules\Invoicing\Actions\DeleteDraft;
use App\Modules\Invoicing\Actions\UpdateDraft;
use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Models\Invoice;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('delete')->label('Elimina bozza')->color('danger')->requiresConfirmation()
                ->action(function (): void {
                    app(DeleteDraft::class)->delete($this->invoice()->id);
                    $this->redirect(InvoiceResource::getUrl('index'));
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lines'] = LinesSchema::toForm($this->invoice()->lineInputs());

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Invoice) {
            throw new LogicException('The invoice form edits invoices.');
        }

        app(UpdateDraft::class)->update($record->id, DraftInput::fromArray(LinesSchema::toInput($data)));

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    private function invoice(): Invoice
    {
        $record = $this->getRecord();

        if (! $record instanceof Invoice) {
            throw new LogicException('The invoice form edits invoices.');
        }

        return $record;
    }
}
