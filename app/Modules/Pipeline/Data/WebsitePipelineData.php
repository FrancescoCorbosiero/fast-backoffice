<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Data;

use App\Kernel\Reference;
use App\Modules\Pipeline\Enums\PipelineKind;
use App\Modules\Pipeline\Enums\PipelineStatus;

final readonly class WebsitePipelineData
{
    public function __construct(
        public int $id,
        public int $websiteId,
        public int $repositoryId,
        public PipelineKind $kind,
        public PipelineStatus $status,
    ) {}

    public function reference(): Reference
    {
        return Reference::to('website_pipeline', $this->id);
    }

    public function isReady(): bool
    {
        return $this->status === PipelineStatus::Ready;
    }
}
