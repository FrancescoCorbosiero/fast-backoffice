<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\RecurringInvoices\Pages;

use App\Modules\Invoicing\Actions\CreateRecurringInvoice as CreateRecurringInvoiceAction;
use App\Modules\Invoicing\Data\RecurringInvoiceInput;
use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Invoicing\Filament\Resources\RecurringInvoices\RecurringInvoiceResource;
use App\Modules\Invoicing\Models\RecurringInvoice;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRecurringInvoice extends CreateRecord
{
    protected static string $resource = RecurringInvoiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $schedule = app(CreateRecurringInvoiceAction::class)->create(RecurringInvoiceInput::fromArray(LinesSchema::toInput($data)));
        $record = RecurringInvoice::query()->findOrFail($schedule->id);

        if (($data['is_active'] ?? true) === false) {
            $record->forceFill(['is_active' => false])->save();
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return RecurringInvoiceResource::getUrl('index');
    }
}
