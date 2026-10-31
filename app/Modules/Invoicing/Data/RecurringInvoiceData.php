<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Dates\Frequency;
use App\Kernel\Reference;
use Carbon\CarbonImmutable;

final readonly class RecurringInvoiceData
{
    /**
     * @param  list<LineInput>  $lines
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $contactId,
        public Frequency $frequency,
        public CarbonImmutable $startedOn,
        public CarbonImmutable $nextIssueOn,
        public ?CarbonImmutable $lastIssuedOn,
        public array $lines,
        public int $paymentTermsDays,
        public bool $autoIssue,
        public bool $isActive,
    ) {}

    public function reference(): Reference
    {
        return Reference::to('recurring_invoice', $this->id);
    }
}
