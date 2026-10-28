<?php

declare(strict_types=1);

namespace App\Modules\Cash\Contracts;

use App\Modules\Cash\Data\ExpectedFlow;
use Carbon\CarbonImmutable;

/**
 * A source of money a module expects to move: receivables, recurring
 * invoices, recurring expenses, domain and hosting renewals. Each module
 * registers its own in its service provider:
 *
 *     $this->app->tag([ReceivablesSchedule::class], CashFlowSchedule::TAG);
 *
 * The projection sums whatever is registered, so it never reads another
 * module's tables and a new source is one class away.
 */
interface CashFlowSchedule
{
    public const string TAG = 'cash.flow-schedules';

    /**
     * Every flow this schedule still expects, up to and including $until.
     *
     * Starts from the next pending occurrence, which is in the past when it
     * is overdue: a cycle not yet issued, an invoice past its due date, a
     * renewal nobody has logged. Those are still expected: the projection
     * counts them in its first month, flagged overdue.
     *
     * @return iterable<ExpectedFlow>
     */
    public function pending(CarbonImmutable $until): iterable;
}
