<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Data;

use App\Support\Shape;

/**
 * What it takes to write a draft: who it's for and the lines.
 */
final readonly class DraftInput
{
    /** @var list<LineInput> */
    public array $lines;

    /**
     * @param  list<LineInput>  $lines
     */
    public function __construct(
        public int $contactId,
        array $lines,
        public ?int $paymentTermsDays = null,
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

        return new self(
            contactId: Shape::int($data, 'contact_id'),
            lines: array_map(fn (array $line): LineInput => LineInput::fromArray($line, $currency), Shape::listOfArrays($data, 'lines')),
            paymentTermsDays: $terms === null ? null : max(0, (int) $terms),
            notes: Shape::nullableString($data, 'notes'),
        );
    }

    /**
     * @return list<array{description: string, quantity: string, unit_price: string, vat_basis_points: int, natura: ?string}>
     */
    public function linesToArray(): array
    {
        return array_map(fn (LineInput $line): array => $line->toArray(), $this->lines);
    }
}
