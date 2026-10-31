<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use Illuminate\Database\Eloquent\Model;

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
 */
class MailEvent extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'immutable_datetime'];
    }
}
