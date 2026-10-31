<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Actions;

use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Queries\DocumentFigures;
use App\Modules\Quotes\Data\QuoteData;
use App\Modules\Quotes\Data\QuoteInput;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Models\Quote;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * A quote can be reworked until the customer answers. One that was
 * already sent goes back to draft: what was sent is no longer what it says.
 */
final readonly class UpdateQuote
{
    public function __construct(
        private ConnectionInterface $db,
        private DocumentFigures $figures,
    ) {}

    /**
     * @throws ValidationException
     * @throws LogicException when the quote was answered
     */
    public function update(int $quoteId, QuoteInput $input): QuoteData
    {
        $customer = $this->figures->customer($input->contactId);
        $computed = $this->figures->compute($input->lines);

        return $this->db->transaction(function () use ($quoteId, $input, $customer, $computed): QuoteData {
            $quote = Quote::query()->lockForUpdate()->findOrFail($quoteId);

            if (! $quote->status->isOpen()) {
                throw new LogicException("Quote {$quoteId} is {$quote->status->value}: an answered quote doesn't change.");
            }

            $issuedOn = ($input->issuedOn ?? $quote->issued_on)->startOfDay();

            $quote->forceFill([
                'status' => QuoteStatus::Draft,
                'issued_on' => $issuedOn->toDateString(),
                'valid_until' => CreateQuote::validity($issuedOn, $input->validUntil ?? $quote->valid_until)->toDateString(),
                'contact_id' => $input->contactId,
                'customer_name' => $customer->name,
                'subject' => $input->subject,
                'lines' => array_map(fn (LineInput $line): array => $line->toArray(), $computed['lines']),
                'totals' => $computed['totals']->toArray(),
                'total_cents' => $computed['totals']->total->cents,
                'notes' => $input->notes,
                'sent_on' => null,
                'pdf_file_id' => null,
            ])->save();

            return $quote->toData();
        });
    }
}
