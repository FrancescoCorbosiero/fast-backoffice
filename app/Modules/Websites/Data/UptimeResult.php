<?php

declare(strict_types=1);

namespace App\Modules\Websites\Data;

/**
 * One check of one site: up means the server answered with 2xx or 3xx.
 */
final readonly class UptimeResult
{
    public function __construct(
        public int $websiteId,
        public bool $isUp,
        public ?int $statusCode,
        public ?int $responseMs,
        public ?string $error = null,
    ) {}
}
