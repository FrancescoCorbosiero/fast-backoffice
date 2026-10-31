<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Files\Queries\ReadFile;
use App\Modules\Invoicing\Models\Invoice;
use LogicException;

/**
 * The XML as stored at issue, for upload through the Agenzia's portal.
 */
final readonly class ExportXml
{
    public function __construct(private ReadFile $files) {}

    /**
     * @return array{filename: string, xml: string}
     */
    public function export(int $invoiceId): array
    {
        $invoice = Invoice::query()->findOrFail($invoiceId);

        if ($invoice->xml_file_id === null || $invoice->sdi_file_name === null) {
            throw new LogicException("Invoice {$invoiceId} has no XML: it is not issued.");
        }

        return ['filename' => $invoice->sdi_file_name, 'xml' => $this->files->contents($invoice->xml_file_id)];
    }
}
