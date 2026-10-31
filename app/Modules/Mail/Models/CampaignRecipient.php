<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use App\Modules\Mail\Data\RecipientData;
use App\Modules\Mail\Enums\RecipientStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $campaign_id
 * @property int $contact_id
 * @property string $email
 * @property RecipientStatus $status
 * @property int $attempts
 * @property ?string $last_error
 * @property ?string $message_id
 * @property ?string $ses_message_id
 * @property string $unsubscribe_token
 * @property ?CarbonImmutable $sent_at
 * @property ?CarbonImmutable $delivered_at
 * @property ?CarbonImmutable $bounced_at
 * @property ?CarbonImmutable $unsubscribed_at
 */
class CampaignRecipient extends Model
{
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['attempts' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RecipientStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'bounced_at' => 'immutable_datetime',
            'unsubscribed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function toData(): RecipientData
    {
        return new RecipientData(
            id: $this->id,
            campaignId: $this->campaign_id,
            contactId: $this->contact_id,
            email: $this->email,
            status: $this->status,
            attempts: $this->attempts,
            lastError: $this->last_error,
            messageId: $this->message_id,
            sesMessageId: $this->ses_message_id,
            unsubscribeToken: $this->unsubscribe_token,
            sentAt: $this->sent_at,
            deliveredAt: $this->delivered_at,
            bouncedAt: $this->bounced_at,
            unsubscribedAt: $this->unsubscribed_at,
        );
    }
}
