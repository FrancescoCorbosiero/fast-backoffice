<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Queries;

use App\Modules\Invoicing\Data\RecurringInvoiceData;
use App\Modules\Invoicing\Models\RecurringInvoice;
use Carbon\CarbonImmutable;

final readonly class RecurringInvoices
{
    /**
     * @return list<RecurringInvoiceData>
     */
    public function active(): array
    {
        return array_values(RecurringInvoice::query()->active()->orderBy('next_issue_on')->get()->map(fn (RecurringInvoice $r): RecurringInvoiceData => $r->toData())->all());
    }

    /**
     * Active schedules whose cycle is due on or before $date.
     *
     * @return list<RecurringInvoiceData>
     */
    public function due(CarbonImmutable $date): array
    {
        return array_values(RecurringInvoice::query()->active()->where('next_issue_on', '<=', $date->toDateString())->orderBy('next_issue_on')->get()
            ->map(fn (RecurringInvoice $r): RecurringInvoiceData => $r->toData())->all());
    }

    public function find(int $id): ?RecurringInvoiceData
    {
        return RecurringInvoice::query()->find($id)?->toData();
    }
}
