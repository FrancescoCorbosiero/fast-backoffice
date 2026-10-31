<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Invoicing\Data\PecMessageData;
use App\Modules\Invoicing\Enums\PecMessageOutcome;
use App\Modules\Invoicing\Models\PecMessage;

/**
 * The owner looked at a message flagged for review and dealt with it.
 */
final readonly class ResolvePecMessage
{
    public function __construct(private RecordAuditEntry $audit) {}

    public function resolve(int $pecMessageId, ?string $note = null): PecMessageData
    {
        $message = PecMessage::query()->findOrFail($pecMessageId);
        $message->forceFill(['outcome' => PecMessageOutcome::Processed, 'note' => $note ?? $message->note])->save();
        $this->audit->record('invoicing.pec_message.resolved', $message->toData()->reference(), ['note' => $note]);

        return $message->toData();
    }
}
