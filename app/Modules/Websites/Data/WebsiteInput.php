<?php

declare(strict_types=1);

namespace App\Modules\Websites\Data;

use App\Kernel\Dates\Frequency;
use App\Kernel\Money\Money;
use App\Support\Shape;
use Carbon\CarbonImmutable;

/**
 * What it takes to describe a site the studio runs: whose it is, where it
 * lives, and how the hosting is paid for.
 */
final readonly class WebsiteInput
{
    public function __construct(
        public int $contactId,
        public string $name,
        public string $url,
        public ?string $hostingProvider = null,
        public ?CarbonImmutable $hostingStartedOn = null,
        public ?CarbonImmutable $hostingRenewsOn = null,
        public ?Frequency $hostingFrequency = null,
        public ?Money $hostingCost = null,
        public ?int $hostingAccountId = null,
        public ?int $hostingCategoryId = null,
        public bool $uptimeEnabled = true,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, string $currency = 'EUR'): self
    {
        $started = Shape::nullableString($data, 'hosting_started_on');
        $renews = Shape::nullableString($data, 'hosting_renews_on');
        $frequency = Shape::nullableString($data, 'hosting_frequency');
        $cost = Shape::nullableString($data, 'hosting_cost');

        return new self(
            contactId: Shape::int($data, 'contact_id'),
            name: trim(Shape::string($data, 'name')),
            url: trim(Shape::string($data, 'url')),
            hostingProvider: Shape::nullableString($data, 'hosting_provider'),
            hostingStartedOn: $started === null ? null : CarbonImmutable::parse($started)->startOfDay(),
            hostingRenewsOn: $renews === null ? null : CarbonImmutable::parse($renews)->startOfDay(),
            hostingFrequency: $frequency === null ? null : Frequency::tryFrom($frequency),
            hostingCost: $cost === null || trim($cost) === '' ? null : Money::fromDecimal($cost, $currency),
            hostingAccountId: Shape::int($data, 'hosting_account_id', 0) > 0 ? Shape::int($data, 'hosting_account_id') : null,
            hostingCategoryId: Shape::int($data, 'hosting_category_id', 0) > 0 ? Shape::int($data, 'hosting_category_id') : null,
            uptimeEnabled: Shape::bool($data, 'uptime_enabled', true),
            notes: Shape::nullableString($data, 'notes'),
        );
    }
}
