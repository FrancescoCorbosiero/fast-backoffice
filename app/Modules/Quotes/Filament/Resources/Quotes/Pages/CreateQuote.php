<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Filament\Resources\Quotes\Pages;

use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Quotes\Actions\CreateQuote as CreateQuoteAction;
use App\Modules\Quotes\Data\QuoteInput;
use App\Modules\Quotes\Filament\Resources\Quotes\QuoteResource;
use App\Modules\Quotes\Models\Quote;
use App\Modules\Quotes\Queries\FindQuote;
use App\Support\Filament\PrefillsFromQuery;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Opened plain, from a contact (?contact_id=) or as the revised version
 * of an earlier quote (?from=): same client, subject, notes and lines,
 * today's dates and a new number.
 */
class CreateQuote extends CreateRecord
{
    use PrefillsFromQuery {
        fillForm as fillFormFromQuery;
    }

    protected static string $resource = QuoteResource::class;

    protected function prefillable(): array
    {
        return ['contact_id'];
    }

    protected function fillForm(): void
    {
        $this->fillFormFromQuery();

        $from = request()->query('from');
        $source = is_string($from) && ctype_digit($from) && (int) $from > 0 ? app(FindQuote::class)->byId((int) $from) : null;

        if ($source === null) {
            return;
        }

        $copy = [
            'contact_id' => $source->contactId,
            'subject' => $source->subject,
            'notes' => $source->notes,
            'lines' => LinesSchema::toForm($source->lines),
        ];
        $this->form->fillPartially($copy, array_keys($copy));
    }

    /**
     * The link that opens the form as a copy of an existing quote.
     */
    public static function duplicateUrl(int $quoteId): string
    {
        return static::getUrl(['from' => $quoteId]);
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
