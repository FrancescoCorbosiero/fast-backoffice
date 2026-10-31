<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Invoicing\Data\DraftInput;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Events\RecurringInvoiceIssued;
use App\Modules\Invoicing\Models\RecurringInvoice;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * One cycle of a recurring invoice: a draft dated on the cycle, issued
 * at once when the schedule opts in. The schedule then moves to its next
 * date with the Kernel's recurrence, so it never drifts.
 */
final readonly class IssueRecurringInvoice
{
    public function __construct(
        private ConnectionInterface $db,
        private CreateDraft $drafts,
        private IssueInvoice $issue,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    /**
     * @throws ValidationException
     */
    public function issue(int $recurringId): InvoiceData
    {
        return $this->db->transaction(function () use ($recurringId): InvoiceData {
            $schedule = RecurringInvoice::query()->lockForUpdate()->findOrFail($recurringId);

            if (! $schedule->is_active) {
                throw new LogicException("Recurring invoice {$recurringId} is paused.");
            }

            $cycle = $schedule->next_issue_on;
            $notes = $schedule->name.' – '.$cycle->isoFormat('MMMM YYYY');
            $invoice = $this->drafts->create(new DraftInput($schedule->contact_id, $schedule->lineInputs(), $schedule->payment_terms_days, $notes));

            if ($schedule->auto_issue) {
                $invoice = $this->issue->issue($invoice->id, $cycle);
            }

            $schedule->forceFill([
                'last_issued_on' => $cycle->toDateString(),
                'next_issue_on' => $schedule->issueDateAfter($cycle)->toDateString(),
            ])->save();

            $this->audit->record('invoicing.recurring_invoice.issued', $schedule->toData()->reference(), ['invoice' => $invoice->id, 'cycle' => $cycle->toDateString(), 'issued' => $schedule->auto_issue]);
            $this->events->dispatch(new RecurringInvoiceIssued($schedule->id, $invoice->id));

            return $invoice;
        });
    }
}
