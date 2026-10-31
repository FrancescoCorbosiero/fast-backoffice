<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Queries;

use App\Modules\Invoicing\Data\PecMessageData;
use App\Modules\Invoicing\Enums\PecMessageOutcome;
use App\Modules\Invoicing\Models\PecMessage;

final readonly class PecMessages
{
    /**
     * Messages the app could not handle on its own, oldest first.
     *
     * @return list<PecMessageData>
     */
    public function needingReview(): array
    {
        return array_values(PecMessage::query()->whereIn('outcome', [PecMessageOutcome::Review->value, PecMessageOutcome::Failed->value])->orderBy('id')->get()
            ->map(fn (PecMessage $m): PecMessageData => $m->toData())->all());
    }

    /**
     * @return list<PecMessageData> newest first
     */
    public function forInvoice(int $invoiceId): array
    {
        return array_values(PecMessage::query()->where('invoice_id', $invoiceId)->orderByDesc('id')->get()
            ->map(fn (PecMessage $m): PecMessageData => $m->toData())->all());
    }
}
