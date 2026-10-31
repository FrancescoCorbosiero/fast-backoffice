<?php

declare(strict_types=1);

namespace App\Modules\Websites\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Invoicing\Actions\DeactivateRecurringInvoice;
use App\Modules\Websites\Data\WebsiteData;
use App\Modules\Websites\Enums\WebsiteStatus;
use App\Modules\Websites\Models\Website;
use Illuminate\Database\ConnectionInterface;

/**
 * Suspended: still the studio's, not monitored. Closed: gone for good;
 * its subscription stops with it.
 */
final readonly class SetWebsiteStatus
{
    public function __construct(
        private ConnectionInterface $db,
        private DeactivateRecurringInvoice $deactivate,
        private RecordAuditEntry $audit,
    ) {}

    public function set(int $websiteId, WebsiteStatus $status): WebsiteData
    {
        return $this->db->transaction(function () use ($websiteId, $status): WebsiteData {
            $website = Website::query()->lockForUpdate()->findOrFail($websiteId);
            $changes = ['status' => $status];

            if ($status === WebsiteStatus::Closed && $website->subscription_id !== null) {
                $this->deactivate->deactivate($website->subscription_id);
            }
            if ($status !== WebsiteStatus::Active) {
                $changes = [...$changes, 'is_up' => null, 'consecutive_failures' => 0, 'down_since' => null];
            }

            $website->forceFill($changes)->save();
            $this->audit->record('websites.website.status', $website->toData()->reference(), ['status' => $status->value]);

            return $website->toData();
        });
    }
}
