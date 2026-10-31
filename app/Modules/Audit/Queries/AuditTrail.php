<?php

declare(strict_types=1);

namespace App\Modules\Audit\Queries;

use App\Kernel\Reference;
use App\Modules\Audit\Data\AuditEntryData;
use App\Modules\Audit\Models\AuditEntry;

final readonly class AuditTrail
{
    /**
     * @return list<AuditEntryData>
     */
    public function latest(int $limit = 50): array
    {
        return array_values(AuditEntry::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditEntry $entry): AuditEntryData => $entry->toData())
            ->all());
    }

    /**
     * Everything recorded about one record, oldest first.
     *
     * @return list<AuditEntryData>
     */
    public function forSubject(Reference $subject): array
    {
        return array_values(AuditEntry::query()
            ->where('subject', $subject->toString())
            ->orderBy('id')
            ->get()
            ->map(fn (AuditEntry $entry): AuditEntryData => $entry->toData())
            ->all());
    }
}
