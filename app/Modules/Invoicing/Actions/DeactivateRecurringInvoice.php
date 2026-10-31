<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Invoicing\Data\RecurringInvoiceData;
use App\Modules\Invoicing\Models\RecurringInvoice;

/**
 * The schedule stops issuing; what it issued stays.
 */
final readonly class DeactivateRecurringInvoice
{
    public function deactivate(int $recurringInvoiceId): RecurringInvoiceData
    {
        $schedule = RecurringInvoice::query()->findOrFail($recurringInvoiceId);
        $schedule->forceFill(['is_active' => false])->save();

        return $schedule->toData();
    }
}
