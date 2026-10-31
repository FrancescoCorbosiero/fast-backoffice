<?php

declare(strict_types=1);

namespace App\Modules\Mail\Jobs;

use App\Modules\Mail\Actions\CompleteCampaign;
use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\Models\Campaign;
use App\Modules\Scheduling\Contracts\ScheduledJob;
use App\Modules\Scheduling\Data\JobResult;
use App\Modules\Settings\Queries\GetMailSettings;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Support\Facades\Date;

/**
 * Closes campaigns whose recipients all reached a final state, and gives a
 * fresh job to recipients left queued past the retry window (a lost queue,
 * a restart): nothing stays "sending" forever.
 */
final readonly class ReconcileCampaigns implements ScheduledJob
{
    public function __construct(
        private CompleteCampaign $complete,
        private GetMailSettings $settings,
        private Bus $bus,
    ) {}

    public function key(): string
    {
        return 'mail.reconcile';
    }

    public function label(): string
    {
        return 'Riconcilia le campagne';
    }

    public function description(): string
    {
        return 'Chiude le campagne completate e rimette in coda i destinatari rimasti fermi.';
    }

    public function defaultCron(): string
    {
        return '45 * * * *';
    }

    public function run(): JobResult
    {
        $lines = [];
        $window = Date::now()->subHours($this->settings->get()->retryHours);

        foreach (Campaign::query()->where('status', CampaignStatus::Sending->value)->orderBy('id')->get() as $campaign) {
            if ($this->complete->completeIfDone($campaign->id)) {
                $lines[] = $campaign->name.': completata';

                continue;
            }

            $stuck = $campaign->recipients()->where('status', RecipientStatus::Queued->value)->where('updated_at', '<', $window)->orderBy('id')->get();
            foreach ($stuck as $recipient) {
                $this->bus->dispatch(new SendCampaignEmail($recipient->id));
            }
            if ($stuck->isNotEmpty()) {
                $lines[] = $campaign->name.': '.$stuck->count().' destinatari rimessi in coda';
            }
        }

        return JobResult::success($lines === [] ? 'Niente da riconciliare.' : implode("\n", $lines));
    }
}
