<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Invoicing\Actions\CreateDraft;
use App\Modules\Invoicing\Actions\CreatePaymentRequest;
use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Quotes\Data\QuoteData;
use App\Modules\Quotes\Enums\AcceptanceOutcome;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Events\QuoteAccepted;
use App\Modules\Quotes\Models\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The customer said yes: in one transaction the quote closes and its lines
 * become an invoice draft or a payment request, through Invoicing. A quote
 * past its validity can still be accepted; the owner decides.
 */
final readonly class AcceptQuote
{
    public function __construct(
        private ConnectionInterface $db,
        private CreateDraft $drafts,
        private CreatePaymentRequest $paymentRequests,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    /**
     * @throws LogicException when the quote was already answered
     * @throws ValidationException when Invoicing refuses the document
     */
    public function accept(int $quoteId, AcceptanceOutcome $outcome, ?CarbonImmutable $acceptedOn = null): QuoteData
    {
        $acceptedOn = ($acceptedOn ?? Date::today())->startOfDay();

        return $this->db->transaction(function () use ($quoteId, $outcome, $acceptedOn): QuoteData {
            $quote = Quote::query()->lockForUpdate()->findOrFail($quoteId);

            if (! $quote->status->isOpen()) {
                throw new LogicException("Quote {$quoteId} is {$quote->status->value}: it was already answered.");
            }

            $data = $quote->toData();
            $input = new DraftInput(contactId: $data->contactId, lines: $data->lines, notes: $data->subject === null ? null : 'Rif. '.$data->title().': '.$data->subject);

            $changes = ['status' => QuoteStatus::Accepted, 'decided_on' => $acceptedOn->toDateString()];
            if ($outcome === AcceptanceOutcome::InvoiceDraft) {
                $changes['invoice_id'] = $this->drafts->create($input)->id;
            } else {
                $changes['payment_request_id'] = $this->paymentRequests->create($input, $acceptedOn)->id;
            }

            $quote->forceFill($changes)->save();

            $this->audit->record('quotes.quote.accepted', $data->reference(), ['number' => $data->numberText, 'outcome' => $outcome->value, 'on' => $acceptedOn->toDateString()]);
            $this->events->dispatch(new QuoteAccepted($quote->id, $outcome, $quote->invoice_id, $quote->payment_request_id));

            return $quote->toData();
        });
    }
}
