<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Pipeline\Data\ChangeRequestData;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Enums\DecidedBy;
use App\Modules\Pipeline\Enums\PipelineStatus;
use App\Modules\Pipeline\Events\ChangeRequestRejected;
use App\Modules\Pipeline\Jobs\CleanupRequest;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Models\WebsitePipeline;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * The preview is not what was wanted: record who said so and why, then
 * let the worker drop the branch and the preview. A rejected onboarding
 * leaves the pipeline unconfigured.
 */
final readonly class RejectChangeRequest
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
        private Bus $bus,
    ) {}

    /**
     * @throws LogicException when the request isn't waiting for a decision
     */
    public function reject(int $changeRequestId, DecidedBy $decidedBy, ?string $reason = null, ?int $decidingContactId = null): ChangeRequestData
    {
        $reason = $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 500);

        $data = $this->db->transaction(function () use ($changeRequestId, $decidedBy, $reason, $decidingContactId): ChangeRequestData {
            $request = ChangeRequest::query()->lockForUpdate()->findOrFail($changeRequestId);

            $request->moveTo(ChangeRequestStatus::Rejected, ['decided_by' => $decidedBy, 'decided_at' => Date::now(), 'decision_reason' => $reason]);
            $this->audit->record('pipeline.change_request.rejected', $request->toData()->reference(), ['decided_by' => $decidedBy->value, 'contact_id' => $decidingContactId, 'reason' => $reason]);

            if ($request->toData()->isOnboarding()) {
                WebsitePipeline::query()->where('website_id', $request->website_id)->where('status', PipelineStatus::Onboarding->value)->update(['status' => PipelineStatus::None->value]);
            }

            return $request->toData();
        });

        $this->events->dispatch(new ChangeRequestRejected($data->id, $data->websiteId, $data->contactId));
        $this->bus->dispatch(new CleanupRequest($data->id));

        return $data;
    }
}
