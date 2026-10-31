<?php

declare(strict_types=1);

namespace App\Modules\Leads\Data;

use App\Kernel\Money\Money;
use App\Support\Shape;
use Carbon\CarbonImmutable;

final readonly class LeadInput
{
    public function __construct(
        public string $name,
        public ?string $company = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $source = null,
        public ?Money $estimatedValue = null,
        public ?CarbonImmutable $nextFollowUpOn = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, string $currency = 'EUR'): self
    {
        $value = Shape::nullableString($data, 'estimated_value');
        $followUp = Shape::nullableString($data, 'next_follow_up_on');
        $email = Shape::nullableString($data, 'email');

        return new self(
            name: trim(Shape::string($data, 'name')),
            company: Shape::nullableString($data, 'company'),
            email: $email === null ? null : mb_strtolower(trim($email)),
            phone: Shape::nullableString($data, 'phone'),
            source: Shape::nullableString($data, 'source'),
            estimatedValue: $value === null || trim($value) === '' ? null : Money::fromDecimal($value, $currency),
            nextFollowUpOn: $followUp === null ? null : CarbonImmutable::parse($followUp)->startOfDay(),
            notes: Shape::nullableString($data, 'notes'),
        );
    }
}
