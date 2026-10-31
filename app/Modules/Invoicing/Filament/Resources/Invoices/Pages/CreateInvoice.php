<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Pages;

use App\Modules\Invoicing\Actions\CreateDraft;
use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Models\Invoice;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $draft = app(CreateDraft::class)->create(DraftInput::fromArray(LinesSchema::toInput($data)));

        return Invoice::query()->findOrFail($draft->id);
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
