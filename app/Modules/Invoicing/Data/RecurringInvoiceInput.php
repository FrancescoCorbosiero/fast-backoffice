<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Kernel\Dates\Frequency;
use App\Support\Shape;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What it takes to start a recurring invoice: who, what, how often, from when.
 */
final readonly class RecurringInvoiceInput
{
    /** @var list<LineInput> */
    public array $lines;

    /**
     * @param  list<LineInput>  $lines
     */
    public function __construct(
        public string $name,
        public int $contactId,
        public Frequency $frequency,
        public CarbonImmutable $startedOn,
        array $lines,
        public ?int $paymentTermsDays = null,
        public bool $autoIssue = false,
        public ?string $notes = null,
    ) {
        $this->lines = $lines;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, string $currency = 'EUR'): self
    {
        $terms = Shape::nullableString($data, 'payment_terms_days');
        $started = Shape::nullableString($data, 'started_on') ?? throw new InvalidArgumentException('A schedule starts on a date.');

        return new self(
            name: Shape::string($data, 'name'),
            contactId: Shape::int($data, 'contact_id'),
            frequency: Frequency::tryFrom(Shape::string($data, 'frequency')) ?? Frequency::Monthly,
            startedOn: CarbonImmutable::parse($started)->startOfDay(),
            lines: array_map(fn (array $line): LineInput => LineInput::fromArray($line, $currency), Shape::listOfArrays($data, 'lines')),
            paymentTermsDays: $terms === null ? null : max(0, (int) $terms),
            autoIssue: Shape::bool($data, 'auto_issue'),
            notes: Shape::nullableString($data, 'notes'),
        );
    }
}
