<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Queries;

use App\Modules\Invoicing\Data\SdiTransmissionData;
use App\Modules\Invoicing\Models\SdiTransmission;

final readonly class SdiTransmissions
{
    /**
     * @return list<SdiTransmissionData> newest first
     */
    public function forInvoice(int $invoiceId): array
    {
        return array_values(SdiTransmission::query()->where('invoice_id', $invoiceId)->orderByDesc('id')->get()
            ->map(fn (SdiTransmission $t): SdiTransmissionData => $t->toData())->all());
    }
}
