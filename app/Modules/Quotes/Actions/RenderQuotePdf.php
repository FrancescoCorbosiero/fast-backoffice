<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Actions;

use App\Modules\Invoicing\Actions\RenderDocumentPdf;
use App\Modules\Invoicing\Queries\DocumentFigures;
use App\Modules\Quotes\Data\QuoteData;

/**
 * The quote on paper, in the studio's document layout.
 */
final readonly class RenderQuotePdf
{
    public function __construct(
        private RenderDocumentPdf $pdf,
        private DocumentFigures $figures,
    ) {}

    public function render(QuoteData $quote): string
    {
        return $this->pdf->render(
            title: $quote->title(),
            seller: $this->figures->seller(),
            customer: $this->figures->customer($quote->contactId),
            date: $quote->issuedOn,
            lines: $quote->lines,
            totals: $quote->totals,
            until: $quote->validUntil,
            subtitle: $quote->subject,
            legalWording: $this->figures->legalWording(),
            notes: $quote->notes,
            footer: 'Preventivo: non è un documento fiscale. Gli importi si intendono validi fino alla data indicata.',
        );
    }

    public static function fileName(QuoteData $quote): string
    {
        return 'preventivo-'.$quote->number.'-'.$quote->year.'.pdf';
    }
}
