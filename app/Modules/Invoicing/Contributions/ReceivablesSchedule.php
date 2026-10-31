<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Contributions;

use App\Modules\Cash\Contracts\CashFlowSchedule;
use App\Modules\Cash\Data\ExpectedFlow;
use App\Modules\Invoicing\Queries\Invoices;
use Carbon\CarbonImmutable;

/**
 * Money in: what clients still owe on issued invoices, on the due date.
 * An overdue balance stays expected until it is paid or credited.
 */
final readonly class ReceivablesSchedule implements CashFlowSchedule
{
    public function __construct(private Invoices $invoices) {}

    public function pending(CarbonImmutable $until): iterable
    {
        foreach ($this->invoices->receivables() as $invoice) {
            $expected = $invoice->dueOn ?? $invoice->issuedOn?->addDays(30);

            if ($expected === null || $expected->greaterThan($until)) {
                continue;
            }

            yield ExpectedFlow::in($expected, $invoice->outstanding(), $invoice->title().' · '.$invoice->customerName, 'receivable', $invoice->reference());
        }
    }
}
