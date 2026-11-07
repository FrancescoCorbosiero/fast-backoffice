<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Pages;

use App\Modules\Invoicing\Actions\DeleteDraft;
use App\Modules\Invoicing\Actions\UpdateDraft;
use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Models\Invoice;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    /**
     * A rejected invoice is edited to be reissued (sdi.md §6): the page says
     * so, and the draft-only action stays out of the way.
     */
    public function getSubheading(): string|Htmlable|null
    {
        if ($this->invoice()->status !== InvoiceStatus::Rejected) {
            return parent::getSubheading();
        }

        return 'Scartata da SdI: correggi le righe, salva e poi usa «Riemetti corretta» dalla pagina della fattura.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label('Torna alla fattura')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                ->visible(fn (): bool => $this->invoice()->status->isIssued())
                ->url(fn (): string => InvoiceResource::getUrl('view', ['record' => $this->invoice()])),
            Action::make('delete')->label('Elimina bozza')->color('danger')->requiresConfirmation()
                ->visible(fn (): bool => ! $this->invoice()->status->isIssued())
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
