<?php

declare(strict_types=1);

namespace App\Modules\Websites\Contributions;

use App\Kernel\Dates\Occurrences;
use App\Modules\Cash\Contracts\CashFlowSchedule;
use App\Modules\Cash\Data\ExpectedFlow;
use App\Modules\Websites\Models\Website;
use Carbon\CarbonImmutable;

/**
 * Money out: each hosting renewal of an active site from its next date,
 * including one overdue that nobody has logged yet (design.md §5.5).
 */
final class HostingRenewalsSchedule implements CashFlowSchedule
{
    public function pending(CarbonImmutable $until): iterable
    {
        $websites = Website::query()->active()
            ->whereNotNull('hosting_renews_on')->whereNotNull('hosting_frequency')
            ->where('hosting_cost_cents', '>', 0)
            ->where('hosting_renews_on', '<=', $until->toDateString())
            ->get();

        foreach ($websites as $website) {
            $cost = $website->hostingCost();
            if ($cost === null || $website->hosting_renews_on === null) {
                continue;
            }

            foreach (Occurrences::until($website->hosting_renews_on, $website->hostingRenewalAfter(...), $until) as $date) {
                yield ExpectedFlow::out($date, $cost, 'Hosting '.$website->name, 'hosting_renewal', $website->hostingAllocationFor($date));
            }
        }
    }
}
