<?php

declare(strict_types=1);

namespace App\Modules\Audit\Actions;

use App\Kernel\Reference;
use App\Modules\Audit\Data\AuditEntryData;
use App\Modules\Audit\Models\AuditEntry;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Date;

/**
 * Writes one line of the audit trail: who did what to which record. Called
 * inside the use case's transaction, so an entry exists only for actions
 * that happened.
 */
final readonly class RecordAuditEntry
{
    public function __construct(private Guard $auth) {}

    /**
     * @param  string  $action  dotted, module first: "settings.business_profile.saved"
     * @param  array<string, mixed>  $details  scalars and arrays of scalars, never models
     */
    public function record(string $action, ?Reference $subject = null, array $details = []): AuditEntryData
    {
        $actorId = $this->auth->id();

        $entry = AuditEntry::query()->create([
            'actor_id' => is_numeric($actorId) ? (int) $actorId : null,
            'action' => $action,
            'subject' => $subject?->toString(),
            'details' => $details,
            'created_at' => Date::now(),
        ]);

        return $entry->toData();
    }
}
