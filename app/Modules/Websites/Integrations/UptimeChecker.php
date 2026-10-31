<?php

declare(strict_types=1);

namespace App\Modules\Websites\Integrations;

use App\Modules\Websites\Data\UptimeResult;
use App\Modules\Websites\Data\WebsiteData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Pings every monitored site at once (hive-crm did them one at a time):
 * one HTTP pool, a short timeout each, no redirects followed beyond the
 * client's default. Up means 2xx or 3xx.
 */
final readonly class UptimeChecker
{
    public const int TIMEOUT_SECONDS = 10;

    public function __construct(private Factory $http) {}

    /**
     * @param  list<WebsiteData>  $websites
     * @return list<UptimeResult>
     */
    public function check(array $websites): array
    {
        if ($websites === []) {
            return [];
        }

        $started = hrtime(true);
        $responses = $this->http->pool(function (Pool $pool) use ($websites): array {
            $requests = [];
            foreach ($websites as $website) {
                $requests[] = $pool->as((string) $website->id)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->connectTimeout(self::TIMEOUT_SECONDS)
                    ->withUserAgent('fast-backoffice uptime check')
                    ->get($website->url);
            }

            return $requests;
        });
        $elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);

        $results = [];
        foreach ($websites as $website) {
            $response = $responses[(string) $website->id] ?? null;

            if ($response instanceof Response) {
                $status = $response->status();
                $results[] = new UptimeResult($website->id, $status >= 200 && $status < 400, $status, $elapsedMs, $status >= 400 ? "HTTP {$status}" : null);
            } elseif ($response instanceof ConnectionException || $response instanceof Throwable) {
                $results[] = new UptimeResult($website->id, false, null, null, mb_substr($response->getMessage(), 0, 200));
            } else {
                $results[] = new UptimeResult($website->id, false, null, null, 'nessuna risposta');
            }
        }

        return $results;
    }
}
