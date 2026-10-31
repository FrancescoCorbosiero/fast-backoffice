<?php

declare(strict_types=1);

namespace App\Modules\Domains\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Domains\Data\DomainData;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;

final readonly class SetDomainStatus
{
    public function __construct(private RecordAuditEntry $audit) {}

    public function set(int $domainId, DomainStatus $status): DomainData
    {
        $domain = Domain::query()->findOrFail($domainId);
        $domain->forceFill(['status' => $status, 'auto_renew' => $status === DomainStatus::Active ? $domain->auto_renew : false])->save();
        $this->audit->record('domains.domain.status', $domain->toData()->reference(), ['status' => $status->value]);

        return $domain->toData();
    }
}
