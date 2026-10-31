<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Data;

use App\Kernel\Reference;
use App\Modules\Repositories\Enums\RepositoryProvider;

final readonly class RepositoryData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public RepositoryProvider $provider,
        public ?int $contactId,
        public ?int $websiteId,
        public string $defaultBranch,
        public ?string $notes,
    ) {}

    public function reference(): Reference
    {
        return Reference::to('repository', $this->id);
    }
}
