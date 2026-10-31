<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Invoicing\Enums\PaymentRequestStatus;
use Carbon\CarbonImmutable;

/**
 * A pro forma: not a fiscal document, never reaches SdI. Paid, it
 * becomes an invoice dated on the payment.
 */
final readonly class PaymentRequestData
{
    /**
     * @param  list<LineInput>  $lines
     */
    public function __construct(
        public int $id,
        public int $year,
        public int $number,
        public string $numberText,
        public PaymentRequestStatus $status,
        public CarbonImmutable $issuedOn,
        public ?CarbonImmutable $dueOn,
        public int $contactId,
        public string $customerName,
        public string $currency,
        public array $lines,
        public DocumentTotals $totals,
        public Money $total,
        public Money $paid,
        public ?CarbonImmutable $paidOn,
        public ?string $notes,
        public ?int $invoiceId,
    ) {}

    public function reference(): Reference
    {
        return Reference::to('payment_request', $this->id);
    }

    public function title(): string
    {
        return 'Richiesta di pagamento '.$this->numberText;
    }
}
