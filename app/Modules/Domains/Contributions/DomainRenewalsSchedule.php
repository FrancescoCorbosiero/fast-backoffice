<?php

declare(strict_types=1);

namespace App\Modules\Domains\Contributions;

use App\Kernel\Dates\Occurrences;
use App\Modules\Cash\Contracts\CashFlowSchedule;
use App\Modules\Cash\Data\ExpectedFlow;
use App\Modules\Domains\Models\Domain;
use Carbon\CarbonImmutable;

/**
 * Money out: each renewal of an active domain with a cost, from its expiry,
 * including one overdue that nobody has logged yet (design.md §5.5).
 */
final class DomainRenewalsSchedule implements CashFlowSchedule
{
    public function pending(CarbonImmutable $until): iterable
    {
        $domains = Domain::query()->active()
            ->where('renewal_cost_cents', '>', 0)
            ->where('expires_on', '<=', $until->toDateString())
            ->get();

        foreach ($domains as $domain) {
            $cost = $domain->renewalCost();
            if ($cost === null) {
                continue;
            }

            foreach (Occurrences::until($domain->expires_on, $domain->expiryAfter(...), $until) as $date) {
                yield ExpectedFlow::out($date, $cost, 'Rinnovo dominio '.$domain->name, 'domain_renewal', $domain->allocationFor($date));
            }
        }
    }
}
