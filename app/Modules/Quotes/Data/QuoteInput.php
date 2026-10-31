<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Data;

use App\Modules\Invoicing\Data\LineInput;
use App\Support\Shape;
use Carbon\CarbonImmutable;

/**
 * What it takes to write a quote: who it's for, the lines, how long it holds.
 */
final readonly class QuoteInput
{
    public const int DEFAULT_VALIDITY_DAYS = 30;

    /** @var list<LineInput> */
    public array $lines;

    /**
     * @param  list<LineInput>  $lines
     */
    public function __construct(
        public int $contactId,
        array $lines,
        public ?CarbonImmutable $issuedOn = null,
        public ?CarbonImmutable $validUntil = null,
        public ?string $subject = null,
        public ?string $notes = null,
    ) {
        $this->lines = $lines;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, string $currency = 'EUR'): self
    {
        $issuedOn = Shape::nullableString($data, 'issued_on');
        $validUntil = Shape::nullableString($data, 'valid_until');

        return new self(
            contactId: Shape::int($data, 'contact_id'),
            lines: array_map(fn (array $line): LineInput => LineInput::fromArray($line, $currency), Shape::listOfArrays($data, 'lines')),
            issuedOn: $issuedOn === null ? null : CarbonImmutable::parse($issuedOn)->startOfDay(),
            validUntil: $validUntil === null ? null : CarbonImmutable::parse($validUntil)->startOfDay(),
            subject: Shape::nullableString($data, 'subject'),
            notes: Shape::nullableString($data, 'notes'),
        );
    }
}
