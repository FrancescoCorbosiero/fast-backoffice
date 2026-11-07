<?php

declare(strict_types=1);

namespace App\Modules\Websites\Actions;

use App\Modules\Websites\Data\UptimeResult;
use App\Modules\Websites\Integrations\UptimeChecker;
use App\Modules\Websites\Models\Website;
use LogicException;

/**
 * One site checked now, from its page: the owner who just fixed a
 * client's server sees whether it answers without re-checking every
 * site from the list. Same checker and same down/recovered rule as the
 * scheduled round.
 */
final readonly class CheckWebsite
{
    public function __construct(
        private UptimeChecker $checker,
        private RecordUptimeResults $record,
    ) {}

    public function check(int $websiteId): UptimeResult
    {
        $website = Website::query()->find($websiteId) ?? throw new LogicException("Website {$websiteId} does not exist.");
        $results = $this->checker->check([$website->toData()]);
        $this->record->record($results);

        return $results[0];
    }
}
