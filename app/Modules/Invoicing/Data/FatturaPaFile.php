<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Money\Money;
use App\Modules\Invoicing\Enums\DocumentType;
use Carbon\CarbonImmutable;

/**
 * A FatturaPA file as read: who, what, how much, as the file declares.
 */
final readonly class FatturaPaFile
{
    /**
     * @param  list<LineInput>  $lines
     */
    public function __construct(
        public PartySnapshot $seller,
        public PartySnapshot $customer,
        public DocumentType $type,
        public string $number,
        public CarbonImmutable $date,
        public string $currency,
        public array $lines,
        public DocumentTotals $totals,
        public ?CarbonImmutable $dueOn,
        public ?Money $declaredTotal,
        public ?string $regime,
    ) {}
}
