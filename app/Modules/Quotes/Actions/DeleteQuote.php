<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Actions;

use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Models\Quote;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Only a draft goes away; anything the customer saw stays on record.
 */
final readonly class DeleteQuote
{
    public function __construct(private ConnectionInterface $db) {}

    public function delete(int $quoteId): void
    {
        $this->db->transaction(function () use ($quoteId): void {
            $quote = Quote::query()->lockForUpdate()->findOrFail($quoteId);

            if ($quote->status !== QuoteStatus::Draft) {
                throw new LogicException("Quote {$quoteId} is {$quote->status->value}: only drafts are deleted.");
            }

            $quote->delete();
        });
    }
}
