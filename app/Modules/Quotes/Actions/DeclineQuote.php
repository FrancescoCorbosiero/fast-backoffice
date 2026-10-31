<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Quotes\Data\QuoteData;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Events\QuoteDeclined;
use App\Modules\Quotes\Models\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * The customer said no, or never answered and the owner closed it.
 */
final readonly class DeclineQuote
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    /**
     * @throws LogicException when the quote was already answered
     */
    public function decline(int $quoteId, ?string $reason = null, ?CarbonImmutable $declinedOn = null): QuoteData
    {
        $declinedOn = ($declinedOn ?? Date::today())->startOfDay();
        $reason = $reason === null ? null : (trim($reason) === '' ? null : mb_substr(trim($reason), 0, 500));

        return $this->db->transaction(function () use ($quoteId, $reason, $declinedOn): QuoteData {
            $quote = Quote::query()->lockForUpdate()->findOrFail($quoteId);

            if (! $quote->status->isOpen()) {
                throw new LogicException("Quote {$quoteId} is {$quote->status->value}: it was already answered.");
            }

            $quote->forceFill(['status' => QuoteStatus::Declined, 'decided_on' => $declinedOn->toDateString(), 'decline_reason' => $reason])->save();

            $this->audit->record('quotes.quote.declined', $quote->toData()->reference(), ['number' => $quote->number_text, 'reason' => $reason, 'on' => $declinedOn->toDateString()]);
            $this->events->dispatch(new QuoteDeclined($quote->id, $reason));

            return $quote->toData();
        });
    }
}
