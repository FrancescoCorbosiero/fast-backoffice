<?php

declare(strict_types=1);

namespace App\Modules\Websites\Events;

final readonly class WebsiteDown
{
    public function __construct(public int $websiteId, public ?int $statusCode, public ?string $error) {}
}
