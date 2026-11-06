<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Pipeline\Data\ChangeRequestData;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Events\ChangeRequestFailed;
use App\Modules\Pipeline\Models\ChangeRequest;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * The run, the build or the deploy didn't make it. Also what a job's
 * failed() hook calls, so it tolerates a request that already ended.
 */
final readonly class FailChangeRequest
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordAuditEntry $audit,
        private Dispatcher $events,
    ) {}

    /**
     * @param  string  $error  for the owner: the raw reason
     * @param  ?string  $clientError  for the client, in the portal: a sentence they can act on, or nothing
     */
    public function fail(int $changeRequestId, string $error, string $logLine = '', ?string $clientError = null): ChangeRequestData
    {
        $data = $this->db->transaction(function () use ($changeRequestId, $error, $logLine, $clientError): ?ChangeRequestData {
            $request = ChangeRequest::query()->lockForUpdate()->findOrFail($changeRequestId);

            if (! $request->status->canBecome(ChangeRequestStatus::Failed)) {
                return null;
            }

            if ($logLine !== '') {
                $request->appendLog($logLine);
            }
            $request->moveTo(ChangeRequestStatus::Failed, ['error' => mb_substr($error, 0, 4000), 'client_error' => $clientError === null ? null : mb_substr($clientError, 0, 1000)]);
            $this->audit->record('pipeline.change_request.failed', $request->toData()->reference(), ['error' => mb_substr($error, 0, 500)]);

            return $request->toData();
        });

        if ($data === null) {
            return ChangeRequest::query()->findOrFail($changeRequestId)->toData();
        }

        $this->events->dispatch(new ChangeRequestFailed($data->id, $data->websiteId, $data->contactId));

        return $data;
    }
}
