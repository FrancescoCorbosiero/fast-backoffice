<?php

declare(strict_types=1);

namespace App\Modules\Websites\Actions;

use App\Modules\Invoicing\Actions\DeactivateRecurringInvoice;
use App\Modules\Websites\Data\WebsiteData;
use App\Modules\Websites\Models\Website;
use Illuminate\Database\ConnectionInterface;

/**
 * The schedule stops; the website forgets it, so a new one can start later.
 */
final readonly class StopSubscription
{
    public function __construct(
        private ConnectionInterface $db,
        private DeactivateRecurringInvoice $deactivate,
    ) {}

    public function stop(int $websiteId): WebsiteData
    {
        return $this->db->transaction(function () use ($websiteId): WebsiteData {
            $website = Website::query()->lockForUpdate()->findOrFail($websiteId);

            if ($website->subscription_id !== null) {
                $this->deactivate->deactivate($website->subscription_id);
                $website->forceFill(['subscription_id' => null])->save();
            }

            return $website->toData();
        });
    }
}
