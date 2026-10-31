<?php

declare(strict_types=1);

namespace App\Modules\Mail\Jobs;

use App\Modules\Mail\Actions\SendToRecipient;
use App\Modules\Mail\Enums\RecipientStatus;
use App\Modules\Mail\MailServiceProvider;
use App\Modules\Mail\Models\CampaignRecipient;
use App\Modules\Settings\Queries\GetMailSettings;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * One recipient, through the rate limiter, retried on failure for as long
 * as the settings allow (a budget in time, not in attempts: a rate-limit
 * release never uses one up — hive-crm's lesson).
 */
final class SendCampaignEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var list<int> seconds between attempts */
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public readonly int $recipientId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new RateLimited(MailServiceProvider::RATE_LIMITER))->releaseAfter(1)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return Date::now()->addHours(app(GetMailSettings::class)->get()->retryHours);
    }

    public function handle(SendToRecipient $send): void
    {
        $send->send($this->recipientId);
    }

    public function failed(?Throwable $exception): void
    {
        $recipient = CampaignRecipient::query()->find($this->recipientId);
        if ($recipient === null || $recipient->status !== RecipientStatus::Queued) {
            return;
        }

        $recipient->forceFill(['status' => RecipientStatus::Failed, 'last_error' => mb_substr($exception?->getMessage() ?? 'Tentativi esauriti', 0, 2000)])->save();
        app(\App\Modules\Mail\Actions\CompleteCampaign::class)->completeIfDone($recipient->campaign_id);
    }

    public function uniqueId(): string
    {
        return (string) $this->recipientId;
    }

    public function retryAfter(): CarbonImmutable
    {
        return Date::now()->addMinutes(5);
    }
}
