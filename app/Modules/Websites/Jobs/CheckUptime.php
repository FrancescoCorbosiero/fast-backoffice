<?php

declare(strict_types=1);

namespace App\Modules\Websites\Jobs;

use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Scheduling\Data\JobResult;
use App\Modules\Websites\Actions\RecordUptimeResults;
use App\Modules\Websites\Data\UptimeResult;
use App\Modules\Websites\Integrations\UptimeChecker;
use App\Modules\Websites\Queries\Websites;

/**
 * Every few minutes: all monitored sites checked concurrently, results on
 * record, down and recovered events for whoever listens.
 */
final readonly class CheckUptime implements ScheduledJob
{
    public function __construct(
        private Websites $websites,
        private UptimeChecker $checker,
        private RecordUptimeResults $record,
    ) {}

    public function key(): string
    {
        return 'websites.uptime-check';
    }

    public function label(): string
    {
        return 'Controllo siti online';
    }

    public function description(): string
    {
        return 'Verifica che ogni sito attivo risponda; segna quelli giù.';
    }

    public function defaultCron(): string
    {
        return '*/10 * * * *';
    }

    public function run(): JobResult
    {
        $websites = $this->websites->monitored();
        $results = $this->checker->check($websites);
        $this->record->record($results);

        $down = array_values(array_filter($results, fn (UptimeResult $r): bool => ! $r->isUp));
        $names = [];
        foreach ($websites as $website) {
            $names[$website->id] = $website->name;
        }
        $lines = array_map(fn (UptimeResult $r): string => ($names[$r->websiteId] ?? $r->websiteId).': '.($r->error ?? 'giù'), $down);

        $output = sprintf('%d siti controllati, %d non rispondono.', count($results), count($down)).($lines === [] ? '' : "\n".implode("\n", $lines));

        return $down === [] ? JobResult::success($output) : JobResult::failure($output);
    }
}
