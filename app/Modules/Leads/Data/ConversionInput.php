<?php

declare(strict_types=1);

namespace App\Modules\Leads\Data;

use App\Modules\Invoicing\Data\LineInput;
use App\Support\Shape;
use Carbon\CarbonImmutable;

/**
 * What converting a lead creates: the contact (an existing one, or a new
 * one from the lead's details), and optionally a quote and a website.
 */
final readonly class ConversionInput
{
    /** @var list<LineInput> */
    public array $quoteLines;

    /**
     * @param  list<LineInput>  $quoteLines
     */
    public function __construct(
        public ?int $existingContactId = null,
        public bool $asCompany = false,
        array $quoteLines = [],
        public ?string $quoteSubject = null,
        public ?CarbonImmutable $quoteValidUntil = null,
        public ?string $websiteName = null,
        public ?string $websiteUrl = null,
    ) {
        $this->quoteLines = $quoteLines;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, string $currency = 'EUR'): self
    {
        $validUntil = Shape::nullableString($data, 'quote_valid_until');
        $lines = Shape::bool($data, 'with_quote', true) ? Shape::listOfArrays($data, 'lines') : [];

        return new self(
            existingContactId: Shape::int($data, 'existing_contact_id', 0) > 0 ? Shape::int($data, 'existing_contact_id') : null,
            asCompany: Shape::bool($data, 'as_company'),
            quoteLines: array_map(fn (array $line): LineInput => LineInput::fromArray($line, $currency), $lines),
            quoteSubject: Shape::nullableString($data, 'quote_subject'),
            quoteValidUntil: $validUntil === null ? null : CarbonImmutable::parse($validUntil)->startOfDay(),
            websiteName: Shape::nullableString($data, 'website_name'),
            websiteUrl: Shape::bool($data, 'with_website') ? Shape::nullableString($data, 'website_url') : null,
        );
    }

    public function wantsQuote(): bool
    {
        return $this->quoteLines !== [];
    }

    public function wantsWebsite(): bool
    {
        return $this->websiteUrl !== null && trim($this->websiteUrl) !== '';
    }
}
