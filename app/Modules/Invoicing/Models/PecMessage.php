<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Modules\Invoicing\Data\PecMessageData;
use App\Modules\Invoicing\Enums\PecMessageKind;
use App\Modules\Invoicing\Enums\PecMessageOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $message_id
 * @property ?string $uid
 * @property ?CarbonImmutable $received_at
 * @property ?string $from_address
 * @property ?string $subject
 * @property PecMessageKind $kind
 * @property PecMessageOutcome $outcome
 * @property ?string $note
 * @property ?int $invoice_id
 * @property ?int $supplier_invoice_id
 * @property ?string $sdi_identifier
 * @property ?string $file_name
 * @property ?int $raw_file_id
 * @property CarbonImmutable $processed_at
 */
class PecMessage extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'kind' => PecMessageKind::class,
            'outcome' => PecMessageOutcome::class,
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function toData(): PecMessageData
    {
        return new PecMessageData(
            id: $this->id,
            messageId: $this->message_id,
            receivedAt: $this->received_at,
            from: $this->from_address,
            subject: $this->subject,
            kind: $this->kind,
            outcome: $this->outcome,
            note: $this->note,
            invoiceId: $this->invoice_id,
            supplierInvoiceId: $this->supplier_invoice_id,
            sdiIdentifier: $this->sdi_identifier,
            fileName: $this->file_name,
            rawFileId: $this->raw_file_id,
            processedAt: $this->processed_at,
        );
    }
}
