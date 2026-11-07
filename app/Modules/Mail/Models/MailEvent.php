<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $sns_message_id
 * @property ?string $event_type
 * @property ?string $ses_message_id
 * @property ?string $header_message_id
 * @property ?int $recipient_id
 * @property string $outcome
 * @property ?string $note
 * @property ?array<string, mixed> $payload
 * @property CarbonImmutable $received_at
 * @property-read ?CampaignRecipient $recipient
 */
class MailEvent extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<CampaignRecipient, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'immutable_datetime'];
    }
}
