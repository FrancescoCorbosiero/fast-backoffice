<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Jobs;

use App\Modules\Invoicing\Actions\IssueRecurringInvoice;
use App\Modules\Invoicing\Queries\RecurringInvoices;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Scheduling\Data\JobResult;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Every day: each active recurring invoice whose cycle has come gets its
 * draft (or its issued invoice). A schedule several cycles behind catches
 * up one cycle per day, so a stalled scheduler never floods a customer.
 */
final readonly class IssueDueRecurringInvoices implements ScheduledJob
{
    public function __construct(
        private RecurringInvoices $schedules,
        private IssueRecurringInvoice $issue,
    ) {}

    public function key(): string
    {
        return 'invoicing.issue-recurring';
    }

    public function label(): string
    {
        return 'Fatture ricorrenti';
    }

    public function description(): string
    {
        return 'Crea la bozza (o emette) la fattura di ogni ricorrenza in scadenza.';
    }

    public function defaultCron(): string
    {
        return '0 6 * * *';
    }

    public function run(): JobResult
    {
        $lines = [];
        $failures = 0;

        foreach ($this->schedules->due(Date::today()) as $schedule) {
            try {
                $invoice = $this->issue->issue($schedule->id);
                $lines[] = "{$schedule->name}: ".$invoice->title();
            } catch (Throwable $exception) {
                $failures++;
                $lines[] = "{$schedule->name}: errore, {$exception->getMessage()}";
            }
        }

        $output = $lines === [] ? 'Nessuna ricorrenza in scadenza.' : implode("\n", $lines);

        return $failures === 0 ? JobResult::success($output) : JobResult::failure($output);
    }
}
