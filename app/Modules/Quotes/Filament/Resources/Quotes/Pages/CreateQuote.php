<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Filament\Resources\Quotes\Pages;

use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Quotes\Actions\CreateQuote as CreateQuoteAction;
use App\Modules\Quotes\Data\QuoteInput;
use App\Modules\Quotes\Filament\Resources\Quotes\QuoteResource;
use App\Modules\Quotes\Models\Quote;
use App\Support\Filament\PrefillsFromQuery;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateQuote extends CreateRecord
{
    use PrefillsFromQuery;

    protected static string $resource = QuoteResource::class;

    protected function prefillable(): array
    {
        return ['contact_id'];
    }

    protected function handleRecordCreation(array $data): Model
    {
        $quote = app(CreateQuoteAction::class)->create(QuoteInput::fromArray(LinesSchema::toInput($data)));

        return Quote::query()->findOrFail($quote->id);
    }

    protected function getRedirectUrl(): string
    {
        return QuoteResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
