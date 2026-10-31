<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Filament\Resources\Quotes\Pages;

use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Quotes\Actions\UpdateQuote;
use App\Modules\Quotes\Data\QuoteInput;
use App\Modules\Quotes\Filament\Resources\Quotes\QuoteResource;
use App\Modules\Quotes\Models\Quote;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $data['lines'] = $record instanceof Quote ? LinesSchema::toForm($record->lineInputs()) : [];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Quote) {
            throw new LogicException('The page edits quotes.');
        }

        app(UpdateQuote::class)->update($record->id, QuoteInput::fromArray(LinesSchema::toInput($data)));

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return QuoteResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
