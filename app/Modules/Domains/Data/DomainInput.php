<?php

declare(strict_types=1);

namespace App\Modules\Domains\Data;

use App\Kernel\Money\Money;
use App\Support\Shape;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class DomainInput
{
    public function __construct(
        public string $name,
        public int $contactId,
        public CarbonImmutable $expiresOn,
        public ?int $websiteId = null,
        public ?string $registrar = null,
        public ?CarbonImmutable $registeredOn = null,
        public int $renewalMonths = 12,
        public bool $autoRenew = true,
        public ?Money $renewalCost = null,
        public ?int $accountId = null,
        public ?int $categoryId = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, string $currency = 'EUR'): self
    {
        $expires = Shape::nullableString($data, 'expires_on') ?? throw new InvalidArgumentException('A domain has an expiry date.');
        $registered = Shape::nullableString($data, 'registered_on');
        $cost = Shape::nullableString($data, 'renewal_cost');

        return new self(
            name: mb_strtolower(trim(Shape::string($data, 'name'))),
            contactId: Shape::int($data, 'contact_id'),
            expiresOn: CarbonImmutable::parse($expires)->startOfDay(),
            websiteId: Shape::int($data, 'website_id', 0) > 0 ? Shape::int($data, 'website_id') : null,
            registrar: Shape::nullableString($data, 'registrar'),
            registeredOn: $registered === null ? null : CarbonImmutable::parse($registered)->startOfDay(),
            renewalMonths: max(1, Shape::int($data, 'renewal_months', 12)),
            autoRenew: Shape::bool($data, 'auto_renew', true),
            renewalCost: $cost === null || trim($cost) === '' ? null : Money::fromDecimal(str_replace(',', '.', trim($cost)), $currency),
            accountId: Shape::int($data, 'account_id', 0) > 0 ? Shape::int($data, 'account_id') : null,
            categoryId: Shape::int($data, 'category_id', 0) > 0 ? Shape::int($data, 'category_id') : null,
            notes: Shape::nullableString($data, 'notes'),
        );
    }
}
