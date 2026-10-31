<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Money\Money;
use App\Modules\Contacts\Data\SdiAddress;
use App\Modules\Invoicing\Enums\DocumentType;
use Carbon\CarbonImmutable;

/**
 * Everything the FatturaPA XML carries, taken from an issued document's
 * frozen snapshots and totals. The builder reads only this.
 */
final readonly class FatturaPaDocument
{
    /**
     * @param  list<LineInput>  $lines
     * @param  list<string>  $causale  free text lines, 200 characters each
     */
    public function __construct(
        public string $progressivoInvio,
        public SdiAddress $sdiAddress,
        public PartySnapshot $seller,
        public string $regime,
        public PartySnapshot $customer,
        public DocumentType $type,
        public string $currency,
        public CarbonImmutable $date,
        public string $number,
        public array $lines,
        public DocumentTotals $totals,
        public ?CarbonImmutable $dueOn,
        public ?string $relatedNumber = null,
        public ?CarbonImmutable $relatedDate = null,
        public array $causale = [],
    ) {}

    /**
     * The SdI file name: IT{seller code}_{progressivo}.xml (sdi.md §3).
     */
    public function fileName(): string
    {
        return 'IT'.self::transmitterCode($this->seller).'_'.$this->progressivoInvio.'.xml';
    }

    public static function transmitterCode(PartySnapshot $seller): string
    {
        return $seller->vatNumber !== null ? $seller->vatNumber->number : ($seller->taxCode ?? '');
    }

    public function amountDue(): Money
    {
        return $this->totals->total;
    }
}
