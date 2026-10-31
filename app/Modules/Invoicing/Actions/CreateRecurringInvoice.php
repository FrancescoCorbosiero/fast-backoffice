<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Invoicing\Data\LineInput;
use App\Modules\Invoicing\Data\RecurringInvoiceData;
use App\Modules\Invoicing\Data\RecurringInvoiceInput;
use App\Modules\Invoicing\DocumentContext;
use App\Modules\Invoicing\Models\RecurringInvoice;
use Illuminate\Validation\ValidationException;

/**
 * A schedule anchored on its first date (design.md §5.3): a draft, or an
 * issued invoice when opted in, on each cycle. Websites start one for a
 * subscription and keep its reference.
 */
final readonly class CreateRecurringInvoice
{
    public function __construct(private DocumentContext $context) {}

    /**
     * @throws ValidationException
     */
    public function create(RecurringInvoiceInput $input): RecurringInvoiceData
    {
        CreateDraft::validateLines($input->lines);
        $this->context->customer($input->contactId);

        if (trim($input->name) === '') {
            throw ValidationException::withMessages(['name' => 'Dai un nome alla ricorrenza.']);
        }

        $schedule = RecurringInvoice::query()->create([
            'name' => trim($input->name),
            'contact_id' => $input->contactId,
            'frequency' => $input->frequency,
            'started_on' => $input->startedOn->toDateString(),
            'next_issue_on' => $input->startedOn->toDateString(),
            'lines' => array_map(fn (LineInput $line): array => $line->toArray(), $input->lines),
            'payment_terms_days' => $input->paymentTermsDays ?? $this->context->defaults()->paymentTermsDays,
            'auto_issue' => $input->autoIssue,
            'is_active' => true,
            'notes' => $input->notes,
        ]);

        return $schedule->toData();
    }
}
