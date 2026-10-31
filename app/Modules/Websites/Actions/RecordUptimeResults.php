<?php

declare(strict_types=1);

namespace App\Modules\Websites\Actions;

use App\Modules\Websites\Data\UptimeResult;
use App\Modules\Websites\Events\WebsiteDown;
use App\Modules\Websites\Events\WebsiteRecovered;
use App\Modules\Websites\Models\Website;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Date;

/**
 * Applies the results of a check round: a site is declared down after
 * two failures in a row (one blip is not an outage) and recovered on the
 * first success after being down.
 */
final readonly class RecordUptimeResults
{
    public const int FAILURES_BEFORE_DOWN = 2;

    public function __construct(private Dispatcher $events) {}

    /**
     * @param  list<UptimeResult>  $results
     */
    public function record(array $results): void
    {
        $now = Date::now();

        foreach ($results as $result) {
            $website = Website::query()->find($result->websiteId);
            if ($website === null) {
                continue;
            }

            $wasDown = $website->down_since !== null;
            $failures = $result->isUp ? 0 : $website->consecutive_failures + 1;
            $isDown = ! $result->isUp && $failures >= self::FAILURES_BEFORE_DOWN;

            $website->forceFill([
                'last_checked_at' => $now,
                'is_up' => $result->isUp,
                'last_status_code' => $result->statusCode,
                'last_response_ms' => $result->responseMs,
                'consecutive_failures' => $failures,
                'down_since' => $isDown ? ($website->down_since ?? $now) : null,
            ])->save();

            if ($isDown && ! $wasDown) {
                $this->events->dispatch(new WebsiteDown($website->id, $result->statusCode, $result->error));
            } elseif ($result->isUp && $wasDown) {
                $this->events->dispatch(new WebsiteRecovered($website->id, $website->down_since));
            }
        }
    }
}
