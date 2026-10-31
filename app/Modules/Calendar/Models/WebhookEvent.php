<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $idempotency_key
 * @property ?string $trigger
 * @property string $outcome
 * @property ?string $note
 * @property ?int $booking_id
 */
class WebhookEvent extends Model
{
    protected $table = 'calendar_webhook_events';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime'];
    }
}
