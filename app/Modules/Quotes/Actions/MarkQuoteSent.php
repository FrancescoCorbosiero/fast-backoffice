<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Files\Actions\StoreFile;
use App\Modules\Quotes\Data\QuoteData;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Events\QuoteSent;
use App\Modules\Quotes\Models\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * The quote went to the customer: the PDF they got is kept as sent.
 */
final readonly class MarkQuoteSent
{
    public function __construct(
        private ConnectionInterface $db,
        private RenderQuotePdf $pdf,
        private StoreFile $files,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    /**
     * @throws LogicException unless the quote is a draft
     */
    public function mark(int $quoteId, ?CarbonImmutable $sentOn = null): QuoteData
    {
        $sentOn = ($sentOn ?? Date::today())->startOfDay();

        return $this->db->transaction(function () use ($quoteId, $sentOn): QuoteData {
            $quote = Quote::query()->lockForUpdate()->findOrFail($quoteId);

            if ($quote->status !== QuoteStatus::Draft) {
                throw new LogicException("Quote {$quoteId} is {$quote->status->value}: only a draft is sent.");
            }

            $data = $quote->toData();
            $file = $this->files->store($data->reference(), 'pdf', RenderQuotePdf::fileName($data), $this->pdf->render($data), 'application/pdf');

            $quote->forceFill(['status' => QuoteStatus::Sent, 'sent_on' => $sentOn->toDateString(), 'pdf_file_id' => $file->id])->save();

            $this->audit->record('quotes.quote.sent', $data->reference(), ['number' => $data->numberText, 'on' => $sentOn->toDateString()]);
            $this->events->dispatch(new QuoteSent($quote->id));

            return $quote->toData();
        });
    }
}
