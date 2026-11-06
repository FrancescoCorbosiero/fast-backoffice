<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Models\WebsitePipeline;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Takes a website out of the pipeline. Its past requests stay on record;
 * an open one blocks the removal.
 */
final readonly class UnlinkRepository
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * @throws LogicException when a request on the website is still open
     */
    public function unlink(int $websitePipelineId): void
    {
        $this->db->transaction(function () use ($websitePipelineId): void {
            $pipeline = WebsitePipeline::query()->lockForUpdate()->findOrFail($websitePipelineId);

            $open = ChangeRequest::query()->where('website_id', $pipeline->website_id)
                ->whereNotIn('status', array_map(fn (ChangeRequestStatus $s): string => $s->value, array_filter(ChangeRequestStatus::cases(), fn (ChangeRequestStatus $s): bool => $s->isTerminal())))
                ->exists();
            if ($open) {
                throw new LogicException("Website {$pipeline->website_id} has an open request: finish it before unlinking the repository.");
            }

            $this->audit->record('pipeline.repository.unlinked', $pipeline->toData()->reference(), ['website_id' => $pipeline->website_id, 'repository_id' => $pipeline->repository_id]);
            $pipeline->delete();
        });
    }
}
