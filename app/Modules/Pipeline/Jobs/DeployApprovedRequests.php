<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Jobs;

use App\Modules\Pipeline\Queries\ChangeRequests;
use App\Modules\Pipeline\Support\PipelineConfig;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Scheduling\Data\JobResult;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Support\Facades\Date;

/**
 * The recovery path: an approved request whose deploy never started (a
 * lost job) or started longer ago than a worker may run (a dead worker)
 * is dispatched again. Approval itself dispatches the deploy at once;
 * this only picks up what fell through.
 */
final readonly class DeployApprovedRequests implements ScheduledJob
{
    public function __construct(
        private ChangeRequests $requests,
        private Bus $bus,
        private PipelineConfig $config,
    ) {}

    public function key(): string
    {
        return 'pipeline.deploy-approved';
    }

    public function label(): string
    {
        return 'Pubblica le richieste approvate';
    }

    public function description(): string
    {
        return 'Rimette in coda le richieste approvate la cui pubblicazione non è partita o si è persa.';
    }

    public function defaultCron(): string
    {
        return '15 * * * *';
    }

    public function run(): JobResult
    {
        $stale = Date::now()->toImmutable()->subSeconds($this->config->workerTimeoutSeconds() + 300);

        $pending = $this->requests->approvedNotDeploying($stale);
        $lines = [];
        foreach ($pending as $request) {
            $this->bus->dispatch($request->isOnboarding() ? new MergeOnboarding($request->id) : new DeployStaticSiteRequest($request->id));
            $lines[] = "#{$request->id} {$request->websiteName}: rimessa in coda";
        }

        return JobResult::success(count($pending).' richieste rimesse in coda.'.($lines === [] ? '' : "\n".implode("\n", $lines)));
    }
}
