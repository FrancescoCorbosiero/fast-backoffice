<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Pipeline\Data\ChangeRequestData;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Enums\DecidedBy;
use App\Modules\Pipeline\Events\ChangeRequestApproved;
use App\Modules\Pipeline\Jobs\DeployStaticSiteRequest;
use App\Modules\Pipeline\Jobs\MergeOnboarding;
use App\Modules\Pipeline\Models\ChangeRequest;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * The preview is good: record who said so, then hand the merge and the
 * deploy to the worker. Nothing touches git here (web containers have
 * none).
 */
final readonly class ApproveChangeRequest
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
        private Bus $bus,
    ) {}

    /**
     * @param  ?int  $decidingContactId  the client who decided, when it was the client
     *
     * @throws LogicException when the request isn't waiting for a decision
     */
    public function approve(int $changeRequestId, DecidedBy $decidedBy, ?int $decidingContactId = null): ChangeRequestData
    {
        $data = $this->db->transaction(function () use ($changeRequestId, $decidedBy, $decidingContactId): ChangeRequestData {
            $request = ChangeRequest::query()->lockForUpdate()->findOrFail($changeRequestId);

            $request->moveTo(ChangeRequestStatus::Approved, ['decided_by' => $decidedBy, 'decided_at' => Date::now(), 'decision_reason' => null]);
            $this->audit->record('pipeline.change_request.approved', $request->toData()->reference(), ['decided_by' => $decidedBy->value, 'contact_id' => $decidingContactId]);

            return $request->toData();
        });

        $this->events->dispatch(new ChangeRequestApproved($data->id, $data->websiteId, $data->contactId));
        $this->bus->dispatch($data->isOnboarding() ? new MergeOnboarding($data->id) : new DeployStaticSiteRequest($data->id));

        return $data;
    }
}
