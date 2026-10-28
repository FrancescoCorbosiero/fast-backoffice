<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Modules\Audit\Data\AuditEntryData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property ?int $actor_id
 * @property string $action
 * @property ?string $subject
 * @property array<string, mixed> $details
 * @property CarbonImmutable $created_at
 */
class AuditEntry extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function toData(): AuditEntryData
    {
        return new AuditEntryData(
            id: $this->id,
            actorId: $this->actor_id,
            action: $this->action,
            subject: $this->subject,
            details: $this->details,
            createdAt: $this->created_at,
        );
    }
}
