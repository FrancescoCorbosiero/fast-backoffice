<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Money\Money;
use App\Support\Shape;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A payment received: how much, when, into which account.
 */
final readonly class PaymentInput
{
    public function __construct(
        public Money $amount,
        public CarbonImmutable $paidOn,
        public int $accountId,
        public ?string $description = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, string $currency = 'EUR'): self
    {
        $paidOn = Shape::nullableString($data, 'paid_on');

        return new self(
            amount: Money::fromDecimal(Shape::nullableString($data, 'amount') ?? '0', $currency),
            paidOn: $paidOn === null ? throw new InvalidArgumentException('A payment has a date.') : CarbonImmutable::parse($paidOn),
            accountId: Shape::int($data, 'account_id'),
            description: Shape::nullableString($data, 'description'),
            notes: Shape::nullableString($data, 'notes'),
        );
    }
}
