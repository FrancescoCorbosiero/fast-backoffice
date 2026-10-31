<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Invoicing\Data\DocumentTotals;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Data\PartySnapshot;
use App\Modules\Invoicing\Integrations\Pdf\CourtesyPdfRenderer;
use Carbon\CarbonImmutable;

/**
 * A non-fiscal document in the studio's layout, for modules that have
 * figures of their own to print (quotes). Returns the PDF bytes.
 */
final readonly class RenderDocumentPdf
{
    public function __construct(private CourtesyPdfRenderer $renderer) {}

    /**
     * @param  list<LineInput>  $lines
     */
    public function render(
        string $title,
        PartySnapshot $seller,
        PartySnapshot $customer,
        CarbonImmutable $date,
        array $lines,
        DocumentTotals $totals,
        ?CarbonImmutable $until = null,
        string $untilLabel = 'Valido fino al',
        ?string $subtitle = null,
        ?string $legalWording = null,
        ?string $notes = null,
        ?string $footer = null,
    ): string {
        return $this->renderer->render(
            title: $title,
            seller: $seller,
            customer: $customer,
            date: $date,
            dueOn: $until,
            lines: $lines,
            totals: $totals,
            legalWording: $legalWording,
            notes: $notes,
            isFiscal: false,
            dueLabel: $untilLabel,
            subtitle: $subtitle,
            footer: $footer,
        );
    }
}
