<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Data;

use App\Modules\Repositories\Enums\RepositoryProvider;
use App\Support\Shape;

final readonly class RepositoryInput
{
    public function __construct(
        public string $name,
        public string $url,
        public ?RepositoryProvider $provider = null,
        public ?int $contactId = null,
        public ?int $websiteId = null,
        public string $defaultBranch = 'main',
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $provider = Shape::nullableString($data, 'provider');

        return new self(
            name: trim(Shape::string($data, 'name')),
            url: trim(Shape::string($data, 'url')),
            provider: $provider === null ? null : RepositoryProvider::tryFrom($provider),
            contactId: Shape::int($data, 'contact_id', 0) > 0 ? Shape::int($data, 'contact_id') : null,
            websiteId: Shape::int($data, 'website_id', 0) > 0 ? Shape::int($data, 'website_id') : null,
            defaultBranch: trim(Shape::string($data, 'default_branch', 'main')) ?: 'main',
            notes: Shape::nullableString($data, 'notes'),
        );
    }
}
