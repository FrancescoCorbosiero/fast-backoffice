<?php

declare(strict_types=1);

namespace App\Modules\Cash\Data;

use App\Kernel\Money\Money;

final readonly class CategoryTotal
{
    public function __construct(
        public ?int $categoryId,
        public string $name,
        public Money $total,
        public int $movements,
    ) {}
}
