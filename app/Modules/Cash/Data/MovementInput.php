<?php

declare(strict_types=1);

namespace App\Modules\Cash\Data;

use App\Kernel\Money\Money;
use App\Kernel\Reference;
use App\Modules\Cash\Enums\Direction;
use App\Support\Shape;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What it takes to record a movement. Allocations are set by the module
 * that owns the document, never from the Cash screens.
 */
final readonly class MovementInput
{
    public function __construct(
        public int $accountId,
        public Direction $direction,
        public Money $amount,
        public CarbonImmutable $occurredOn,
        public string $description,
        public ?int $categoryId = null,
        public ?int $contactId = null,
        public ?Reference $allocation = null,
        public bool $isTaxable = true,
        public ?string $notes = null,
    ) {}

    /**
     * From form state: "amount" as a decimal string, "occurred_on" as a date.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, string $currency = 'EUR'): self
    {
        $occurredOn = Shape::nullableString($data, 'occurred_on');

        return new self(
            accountId: Shape::int($data, 'account_id'),
            direction: Direction::tryFrom(Shape::string($data, 'direction')) ?? throw new InvalidArgumentException('A movement goes in or out.'),
            amount: Money::fromDecimal(Shape::string($data, 'amount', '0'), $currency),
            occurredOn: $occurredOn === null ? throw new InvalidArgumentException('A movement has a date.') : CarbonImmutable::parse($occurredOn),
            description: Shape::string($data, 'description'),
            categoryId: Shape::int($data, 'category_id', 0) > 0 ? Shape::int($data, 'category_id') : null,
            contactId: Shape::int($data, 'contact_id', 0) > 0 ? Shape::int($data, 'contact_id') : null,
            isTaxable: Shape::bool($data, 'is_taxable', true),
            notes: Shape::nullableString($data, 'notes'),
        );
    }

    public function withAllocation(?Reference $allocation): self
    {
        return new self(
            $this->accountId, $this->direction, $this->amount, $this->occurredOn, $this->description,
            $this->categoryId, $this->contactId, $allocation, $this->isTaxable, $this->notes,
        );
    }
}
