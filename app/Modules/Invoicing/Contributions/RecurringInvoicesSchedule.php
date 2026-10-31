<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Contributions;

use App\Kernel\Dates\Occurrences;
use App\Modules\Cash\Contracts\CashFlowSchedule;
use App\Modules\Cash\Data\ExpectedFlow;
use App\Modules\Invoicing\DocumentContext;
use App\Modules\Invoicing\Models\RecurringInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Money in: each cycle of an active recurring invoice, expected when its
 * payment terms run out, from the next cycle on (an overdue cycle counts).
 */
final readonly class RecurringInvoicesSchedule implements CashFlowSchedule
{
    public function __construct(private DocumentContext $context) {}

    public function pending(CarbonImmutable $until): iterable
    {
        $schedules = RecurringInvoice::query()->active()->get();

        foreach ($schedules as $schedule) {
            try {
                $total = $this->context->compute($schedule->lineInputs(), 'EUR')['totals']->total;
            } catch (ValidationException) {
                continue;
            }

            if (! $total->isPositive()) {
                continue;
            }

            foreach (Occurrences::until($schedule->next_issue_on, $schedule->issueDateAfter(...), $until) as $cycle) {
                $expected = $cycle->addDays($schedule->payment_terms_days);

                if ($expected->lessThanOrEqualTo($until)) {
                    yield ExpectedFlow::in($expected, $total, $schedule->name, 'recurring_invoice', $schedule->toData()->reference());
                }
            }
        }
    }
}
