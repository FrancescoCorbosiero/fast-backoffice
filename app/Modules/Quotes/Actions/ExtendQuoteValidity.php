<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Quotes\Data\QuoteData;
use App\Modules\Quotes\Models\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The customer is still thinking: the quote holds for longer. Only the
 * validity moves; a sent quote stays sent, with the PDF it went out as,
 * since the figures the customer has in hand haven't changed.
 */
final readonly class ExtendQuoteValidity
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * @throws ValidationException when the new date is before the quote's date
     * @throws LogicException when the quote was already answered
     */
    public function extend(int $quoteId, CarbonImmutable $validUntil): QuoteData
    {
        $validUntil = $validUntil->startOfDay();

        return $this->db->transaction(function () use ($quoteId, $validUntil): QuoteData {
            $quote = Quote::query()->lockForUpdate()->findOrFail($quoteId);

            if (! $quote->status->isOpen()) {
                throw new LogicException("Quote {$quoteId} is {$quote->status->value}: an answered quote doesn't change.");
            }

            if ($validUntil->lessThan($quote->issued_on)) {
                throw ValidationException::withMessages(['valid_until' => 'La validità non può finire prima della data del preventivo.']);
            }

            $previous = $quote->valid_until->toDateString();
            $quote->forceFill(['valid_until' => $validUntil->toDateString()])->save();

            $data = $quote->toData();
            $this->audit->record('quotes.quote.extended', $data->reference(), ['number' => $data->numberText, 'from' => $previous, 'to' => $validUntil->toDateString()]);

            return $data;
        });
    }
}
