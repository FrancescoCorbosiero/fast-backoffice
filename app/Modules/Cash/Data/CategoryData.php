<?php

declare(strict_types=1);

namespace App\Modules\Cash\Data;

use App\Modules\Cash\Enums\CategoryKind;

final readonly class CategoryData
{
    public function __construct(
        public int $id,
        public string $name,
        public CategoryKind $kind,
        public bool $isActive,
    ) {}
}
