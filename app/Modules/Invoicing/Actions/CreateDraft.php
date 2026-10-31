<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\DocumentContext;
use App\Modules\Invoicing\Enums\DocumentType;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * A draft: editable, deletable, no number.
 */
final readonly class CreateDraft
{
    public function __construct(
        private ConnectionInterface $db,
        private DocumentContext $context,
        private Repository $config,
    ) {}

    /**
     * @throws ValidationException
     */
    public function create(DraftInput $input, DocumentType $type = DocumentType::Invoice, ?int $relatedInvoiceId = null): InvoiceData
    {
        self::validateLines($input->lines);
        $customer = $this->context->customer($input->contactId);
        $defaults = $this->context->defaults();
        $currency = $this->currency();

        if ($type === DocumentType::CreditNote && $relatedInvoiceId === null) {
            throw ValidationException::withMessages(['related_invoice_id' => 'Una nota di credito storna una fattura.']);
        }

        return $this->db->transaction(function () use ($input, $type, $relatedInvoiceId, $customer, $defaults, $currency): InvoiceData {
            $invoice = Invoice::query()->create([
                'type' => $type,
                'status' => InvoiceStatus::Draft,
                'series' => $type === DocumentType::CreditNote ? $defaults->creditNoteSeries : $defaults->invoiceSeries,
                'contact_id' => $customer->id,
                'customer_name' => $customer->displayName,
                'related_invoice_id' => $relatedInvoiceId,
                'currency' => $currency,
                'lines' => $input->linesToArray(),
                'payment_terms_days' => $input->paymentTermsDays ?? $defaults->paymentTermsDays,
                'notes' => $input->notes,
            ]);

            return $invoice->toData();
        });
    }

    /**
     * @param  list<LineInput>  $lines
     *
     * @throws ValidationException
     */
    public static function validateLines(array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Serve almeno una riga.']);
        }

        $errors = [];
        foreach ($lines as $index => $line) {
            if ($line->description === '') {
                $errors["lines.{$index}.description"] = 'Ogni riga ha una descrizione.';
            }
            if ($line->quantity->isZero()) {
                $errors["lines.{$index}.quantity"] = 'La quantità non può essere zero.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function currency(): string
    {
        $currency = $this->config->get('backoffice.currency');

        return is_string($currency) ? $currency : 'EUR';
    }
}
