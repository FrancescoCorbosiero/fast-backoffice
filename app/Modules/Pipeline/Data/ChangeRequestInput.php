<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Data;

use App\Support\Shape;

/**
 * What a client (or the owner on their behalf) submits: which website,
 * and what they want changed, in their own words.
 */
final readonly class ChangeRequestInput
{
    public function __construct(
        public int $contactId,
        public int $websiteId,
        public string $requestText,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            contactId: Shape::int($data, 'contact_id'),
            websiteId: Shape::int($data, 'website_id'),
            requestText: trim(Shape::string($data, 'request_text')),
        );
    }
}
