<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Data;

use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Invoicing\Data\DocumentTotals;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Quotes\Enums\QuoteStatus;
use Carbon\CarbonImmutable;

/**
 * A quotation: non-fiscal, numbered in its own series, valid until a date.
 */
final readonly class QuoteData
{
    /**
     * @param  list<LineInput>  $lines
     */
    public function __construct(
        public int $id,
        public int $year,
        public int $number,
        public string $numberText,
        public QuoteStatus $status,
        public CarbonImmutable $issuedOn,
        public CarbonImmutable $validUntil,
        public int $contactId,
        public string $customerName,
        public ?string $subject,
        public string $currency,
        public array $lines,
        public DocumentTotals $totals,
        public Money $total,
        public ?string $notes,
        public ?CarbonImmutable $sentOn,
        public ?CarbonImmutable $decidedOn,
        public ?string $declineReason,
        public ?int $invoiceId,
        public ?int $paymentRequestId,
        public ?int $pdfFileId,
    ) {}

    public function reference(): Reference
    {
        return Reference::to('quote', $this->id);
    }

    public function title(): string
    {
        return 'Preventivo '.$this->numberText;
    }

    /**
     * Past its validity and still unanswered. Dates are compared, never diffed.
     */
    public function isExpired(CarbonImmutable $today): bool
    {
        return $this->status->isOpen() && $this->validUntil->lessThan($today->startOfDay());
    }

    /**
     * The status as the screens show it on a day.
     */
    public function statusOn(CarbonImmutable $today): QuoteStatus
    {
        return $this->isExpired($today) ? QuoteStatus::Expired : $this->status;
    }

    /**
     * Still open and valid for at most this many more days (today counts as 0).
     */
    public function expiresWithin(CarbonImmutable $today, int $days): bool
    {
        $today = $today->startOfDay();

        return $this->status->isOpen()
            && $this->validUntil->greaterThanOrEqualTo($today)
            && $this->validUntil->lessThanOrEqualTo($today->addDays($days));
    }
}
