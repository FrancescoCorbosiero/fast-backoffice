<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Cash\Queries\AllocatedMovements;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\PaymentRequest;
use App\Modules\Invoicing\Models\SupplierInvoice;

/**
 * Recomputes every cached paid amount from the cash movements, the
 * rebuild that design.md §2 (principle 1) requires for a cached value.
 *
 * @return int the number of documents whose cached amount was wrong
 */
final readonly class RebuildPaidAmounts
{
    public function __construct(private AllocatedMovements $allocated) {}

    public function rebuild(): int
    {
        $fixed = 0;

        foreach ([Invoice::class, PaymentRequest::class, SupplierInvoice::class] as $model) {
            $fixed += $this->rebuildModel($model);
        }

        return $fixed;
    }

    /**
     * @param  class-string<Invoice|PaymentRequest|SupplierInvoice>  $model
     */
    private function rebuildModel(string $model): int
    {
        $fixed = 0;

        // Money paid to a supplier leaves the accounts: its paid amount is the outgoing total.
        $sign = $model === SupplierInvoice::class ? -1 : 1;

        $model::query()->orderBy('id')->chunkById(200, function ($documents) use (&$fixed, $sign): void {
            $references = [];
            foreach ($documents as $document) {
                $references[$document->id] = $document->toData()->reference();
            }

            $totals = $this->allocated->totals(array_values($references));

            foreach ($documents as $document) {
                $paid = $sign * $totals[$references[$document->id]->toString()]->cents;

                if ($document->paid_cents !== $paid) {
                    $document->forceFill(['paid_cents' => $paid])->save();
                    $fixed++;
                }
            }
        });

        return $fixed;
    }
}
