<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Integrations\Pdf;

use App\Modules\Invoicing\Data\DocumentTotals;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Data\PartySnapshot;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;

/**
 * The courtesy PDF: the same figures as the XML, laid out for people.
 * dompdf, as hive-crm had; the renderer is the only place that knows.
 */
final class CourtesyPdfRenderer
{
    /**
     * @param  list<LineInput>  $lines
     */
    public function render(
        string $title,
        PartySnapshot $seller,
        PartySnapshot $customer,
        CarbonImmutable $date,
        ?CarbonImmutable $dueOn,
        array $lines,
        DocumentTotals $totals,
        ?string $legalWording,
        ?string $notes,
        ?string $relatedTitle = null,
        bool $isFiscal = true,
    ): string {
        return Pdf::loadView('invoicing.document', [
            'title' => $title,
            'seller' => $seller,
            'customer' => $customer,
            'date' => $date,
            'dueOn' => $dueOn,
            'lines' => $lines,
            'totals' => $totals,
            'legalWording' => $legalWording,
            'notes' => $notes,
            'relatedTitle' => $relatedTitle,
            'isFiscal' => $isFiscal,
        ])->setPaper('a4')->output();
    }
}
