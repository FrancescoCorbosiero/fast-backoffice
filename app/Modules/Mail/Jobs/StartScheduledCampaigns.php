<?php

declare(strict_types=1);

namespace App\Modules\Mail\Jobs;

use App\Modules\Mail\Actions\StartCampaign;
use App\Modules\Mail\Queries\Campaigns;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Scheduling\Data\JobResult;
use Illuminate\Support\Facades\Date;
use Throwable;

final readonly class StartScheduledCampaigns implements ScheduledJob
{
    public function __construct(
        private Campaigns $campaigns,
        private StartCampaign $start,
    ) {}

    public function key(): string
    {
        return 'mail.start-scheduled';
    }

    public function label(): string
    {
        return 'Avvia le campagne programmate';
    }

    public function description(): string
    {
        return 'Mette in coda le campagne la cui ora è arrivata.';
    }

    public function defaultCron(): string
    {
        return '*/5 * * * *';
    }

    public function run(): JobResult
    {
        $lines = [];
        $failures = 0;
        foreach ($this->campaigns->dueToStart(Date::now()) as $campaign) {
            try {
                $started = $this->start->start($campaign->id);
                $lines[] = $campaign->name.': in coda per '.$started->total().' destinatari';
            } catch (Throwable $exception) {
                $failures++;
                $lines[] = $campaign->name.': errore, '.$exception->getMessage();
            }
        }

        $output = $lines === [] ? 'Nessuna campagna da avviare.' : implode("\n", $lines);

        return $failures === 0 ? JobResult::success($output) : JobResult::failure($output);
    }
}
