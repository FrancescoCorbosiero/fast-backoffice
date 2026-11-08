<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Data;

use App\Support\Shape;

/**
 * What a client (or the owner on their behalf) submits: which website,
 * what they want changed, in their own words, and the photos that go
 * with it. attachmentsFrom (the panel's "Ripeti richiesta" only) copies
 * the photos of an earlier request of the same client and website.
 */
final readonly class ChangeRequestInput
{
    /**
     * @param  list<AttachmentUpload>  $attachments
     */
    public function __construct(
        public int $contactId,
        public int $websiteId,
        public string $requestText,
        public array $attachments = [],
        public ?int $attachmentsFrom = null,
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
