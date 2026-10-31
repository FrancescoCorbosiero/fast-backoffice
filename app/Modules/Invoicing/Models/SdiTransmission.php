<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Modules\Invoicing\Data\SdiTransmissionData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $invoice_id
 * @property string $file_name
 * @property string $message_id
 * @property string $sent_to
 * @property CarbonImmutable $sent_at
 * @property ?CarbonImmutable $accepted_at
 * @property ?CarbonImmutable $delivered_to_sdi_at
 * @property ?string $transport_error
 * @property ?string $outcome
 * @property ?CarbonImmutable $outcome_at
 * @property ?string $sdi_identifier
 */
class SdiTransmission extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'delivered_to_sdi_at' => 'immutable_datetime',
            'outcome_at' => 'immutable_datetime',
        ];
    }

    public function toData(): SdiTransmissionData
    {
        return new SdiTransmissionData(
            id: $this->id,
            invoiceId: $this->invoice_id,
            fileName: $this->file_name,
            messageId: $this->message_id,
            sentTo: $this->sent_to,
            sentAt: $this->sent_at,
            acceptedAt: $this->accepted_at,
            deliveredToSdiAt: $this->delivered_to_sdi_at,
            transportError: $this->transport_error,
            outcome: $this->outcome,
            outcomeAt: $this->outcome_at,
            sdiIdentifier: $this->sdi_identifier,
        );
    }
}
