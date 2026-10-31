<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Settings\Data\BusinessProfile;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Queries\GetBusinessProfile;
use Illuminate\Database\ConnectionInterface;

final readonly class SaveBusinessProfile
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
    ) {}

    public function save(BusinessProfile $profile): void
    {
        $this->db->transaction(function () use ($profile): void {
            Setting::write(GetBusinessProfile::KEY, $profile->toArray());
            $this->audit->record('settings.business_profile.saved', details: [
                'name' => $profile->displayName(),
                'vat_number' => $profile->vatNumber,
                'regime' => $profile->regime->value,
            ]);
        });
    }
}
