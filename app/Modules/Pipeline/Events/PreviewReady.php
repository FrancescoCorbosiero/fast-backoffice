<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Events;

/**
 * The run produced commits and a preview; the request waits for a decision.
 */
final readonly class PreviewReady
{
    public function __construct(public int $changeRequestId, public int $websiteId, public int $contactId, public ?string $previewUrl) {}
}
