<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Queries;

use App\Modules\Invoicing\Data\PaymentRequestData;
use App\Modules\Invoicing\Enums\PaymentRequestStatus;
use App\Modules\Invoicing\Models\PaymentRequest;

final readonly class PaymentRequests
{
    public function find(int $id): ?PaymentRequestData
    {
        return PaymentRequest::query()->find($id)?->toData();
    }

    /**
     * @return list<PaymentRequestData>
     */
    public function open(): array
    {
        return array_values(PaymentRequest::query()->where('status', PaymentRequestStatus::Open->value)->orderBy('due_on')->get()
            ->map(fn (PaymentRequest $r): PaymentRequestData => $r->toData())->all());
    }

    /**
     * @return list<PaymentRequestData> newest first
     */
    public function forContact(int $contactId, int $limit = 100): array
    {
        return array_values(PaymentRequest::query()->where('contact_id', $contactId)->orderByDesc('issued_on')->limit($limit)->get()
            ->map(fn (PaymentRequest $r): PaymentRequestData => $r->toData())->all());
    }
}
