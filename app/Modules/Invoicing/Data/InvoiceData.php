<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Invoicing\Enums\DocumentType;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\PaymentState;
use App\Modules\Invoicing\Enums\SendChannel;
use Carbon\CarbonImmutable;

/**
 * An invoice or credit note as other modules see it.
 */
final readonly class InvoiceData
{
    /**
     * @param  list<LineInput>  $lines
     * @param  list<array{code: string, description: string, hint: ?string}>  $sdiErrors
     */
    public function __construct(
        public int $id,
        public DocumentType $type,
        public InvoiceStatus $status,
        public string $series,
        public ?int $year,
        public ?int $number,
        public ?string $numberText,
        public ?CarbonImmutable $issuedOn,
        public ?CarbonImmutable $dueOn,
        public int $contactId,
        public string $customerName,
        public ?int $relatedInvoiceId,
        public string $currency,
        public array $lines,
        public int $paymentTermsDays,
        public ?string $notes,
        public ?PartySnapshot $seller,
        public ?PartySnapshot $customer,
        public ?string $regime,
        public ?DocumentTotals $totals,
        public Money $total,
        public Money $paid,
        public ?string $sdiFileName,
        public ?string $progressivoInvio,
        public ?int $xmlFileId,
        public ?int $pdfFileId,
        public ?CarbonImmutable $sentAt,
        public ?SendChannel $sentChannel,
        public ?string $sdiIdentifier = null,
        public ?CarbonImmutable $sdiOutcomeAt = null,
        public array $sdiErrors = [],
        public ?CarbonImmutable $rejectedAt = null,
    ) {}

    /**
     * Last day to resend a rejected invoice with the same number and date
     * (sdi.md §6): five days from the rejection.
     */
    public function resendDeadline(): ?CarbonImmutable
    {
        if ($this->status !== InvoiceStatus::Rejected || $this->rejectedAt === null) {
            return null;
        }

        return $this->rejectedAt->startOfDay()->addDays(5);
    }

    public function canBeResent(CarbonImmutable $today): bool
    {
        $deadline = $this->resendDeadline();

        return $deadline !== null && ! $today->startOfDay()->greaterThan($deadline);
    }

    public function reference(): Reference
    {
        return Reference::to('invoice', $this->id);
    }

    public function outstanding(): Money
    {
        $outstanding = $this->total->minus($this->paid);

        return $outstanding->isNegative() ? Money::zero($this->currency) : $outstanding;
    }

    public function paymentState(CarbonImmutable $today): PaymentState
    {
        return PaymentState::derive($this->total->cents, $this->paid->cents, $this->dueOn, $today);
    }

    /**
     * The label the document goes by: its number once issued, "Bozza" before.
     */
    public function title(): string
    {
        return $this->numberText === null ? $this->type->label().' (bozza)' : $this->type->label().' '.$this->numberText;
    }

    /**
     * Days left to reach SdI: 12 from the operation (sdi.md §3). Negative
     * when late; null when not applicable.
     */
    public function daysLeftToSend(CarbonImmutable $today): ?int
    {
        if (! $this->status->canBeSent() || $this->issuedOn === null) {
            return null;
        }

        $deadline = $this->issuedOn->addDays(12);

        return (int) $today->startOfDay()->diffInDays($deadline, absolute: false);
    }
}
