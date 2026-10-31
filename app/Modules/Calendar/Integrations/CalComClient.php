<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Integrations;

use App\Modules\Calendar\Data\BookingInput;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cal.com's API, for the periodic sync: the bookings in a window, as
 * BookingInputs. One page at a time, up to a sane number of pages.
 */
final readonly class CalComClient
{
    private const int MAX_PAGES = 20;

    public function __construct(
        private Factory $http,
        private Repository $config,
    ) {}

    public function isConfigured(): bool
    {
        $key = $this->config->get('calcom.api_key');

        return is_string($key) && trim($key) !== '';
    }

    /**
     * @return list<BookingInput>
     *
     * @throws RuntimeException when the API can't be read
     */
    public function bookingsBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = $this->config->get('calcom.api_key');
        $url = $this->config->get('calcom.api_url');
        $base = rtrim(is_string($url) && $url !== '' ? $url : 'https://api.cal.com/v2', '/');
        $configuredVersion = $this->config->get('calcom.api_version');
        $version = is_string($configuredVersion) && $configuredVersion !== '' ? $configuredVersion : '2024-08-13';
        $bookings = [];

        foreach (range(0, self::MAX_PAGES - 1) as $page) {
            $response = $this->http
                ->withToken(is_string($key) ? $key : '')
                ->withHeaders(['cal-api-version' => $version])
                ->acceptJson()
                ->timeout(20)
                ->get($base.'/bookings', ['afterStart' => $from->toIso8601String(), 'beforeEnd' => $to->toIso8601String(), 'take' => 100, 'skip' => $page * 100]);

            if (! $response->successful()) {
                throw new RuntimeException('Cal.com ha risposto '.$response->status().'.');
            }

            $json = $response->json();
            $data = is_array($json) ? ($json['data'] ?? $json['bookings'] ?? $json) : [];
            if (! is_array($data) || $data === []) {
                break;
            }

            $count = 0;
            foreach ($data as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $count++;
                try {
                    $bookings[] = CalComPayload::fromApi($item);
                } catch (InvalidArgumentException) {
                    // A record without the essentials is skipped, not fatal.
                }
            }

            if ($count < 100) {
                break;
            }
        }

        return $bookings;
    }
}
