<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Queries;

use App\Modules\Invoicing\Actions\CreateDraft;
use App\Modules\Invoicing\Data\DocumentTotals;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Data\PartySnapshot;
use App\Modules\Invoicing\DocumentContext;
use App\Modules\Settings\Enums\RegimeFiscale;
use Illuminate\Validation\ValidationException;

/**
 * What another module needs to put figures on a document of its own:
 * the one way lines become totals, and the parties as the studio's
 * documents print them. Quotes use it; nothing here touches invoices.
 */
final readonly class DocumentFigures
{
    public function __construct(private DocumentContext $context, private CreateDraft $drafts) {}

    public function currency(): string
    {
        return $this->drafts->currency();
    }

    /**
     * The lines with their natura filled in, and the totals. The stamp
     * duty is never charged on a non-fiscal document.
     *
     * @param  list<LineInput>  $lines
     * @return array{lines: list<LineInput>, totals: DocumentTotals}
     *
     * @throws ValidationException when a line can't be priced
     */
    public function compute(array $lines): array
    {
        CreateDraft::validateLines($lines);

        return $this->context->compute($lines, $this->currency(), chargeStampDuty: false);
    }

    public function seller(): PartySnapshot
    {
        return PartySnapshot::ofSeller($this->context->profile());
    }

    /**
     * @throws ValidationException when the contact doesn't exist
     */
    public function customer(int $contactId): PartySnapshot
    {
        return PartySnapshot::ofCustomer($this->context->customer($contactId));
    }

    /**
     * The wording the regime requires on documents, if any.
     */
    public function legalWording(): ?string
    {
        return $this->context->profile()->regime === RegimeFiscale::Forfettario ? $this->context->defaults()->legalWording : null;
    }
}
