<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Queries;

use App\Modules\Pipeline\Data\ChangeRequestData;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Models\ChangeRequest;
use Carbon\CarbonImmutable;

final readonly class ChangeRequests
{
    public function find(int $id): ?ChangeRequestData
    {
        return ChangeRequest::query()->with('attachments')->find($id)?->toData();
    }

    /**
     * One client's requests, newest first.
     *
     * @return list<ChangeRequestData>
     */
    public function forContact(int $contactId): array
    {
        return $this->map(ChangeRequest::query()->with('attachments')->forContact($contactId)->orderByDesc('id')->get()->all());
    }

    /**
     * Previews waiting for a decision, oldest first.
     *
     * @return list<ChangeRequestData>
     */
    public function awaitingDecision(): array
    {
        return $this->map(ChangeRequest::query()->with('attachments')->where('status', ChangeRequestStatus::PreviewReady->value)->orderBy('id')->get()->all());
    }

    /**
     * Approved requests whose deploy never started, or started longer ago
     * than a worker may run: the ones the recovery job re-dispatches.
     *
     * @return list<ChangeRequestData>
     */
    public function approvedNotDeploying(CarbonImmutable $staleBefore): array
    {
        return $this->map(ChangeRequest::query()
            ->with('attachments')
            ->where('status', ChangeRequestStatus::Approved->value)
            ->where(fn ($query) => $query->whereNull('deploy_started_at')->orWhere('deploy_started_at', '<', $staleBefore))
            ->orderBy('id')->get()->all());
    }

    /**
     * What the dashboard shows: previews to approve, deploys in flight,
     * failures of the last month. One query.
     *
     * @return array{preview_ready: int, approved: int, failed: int}
     */
    public function counts(CarbonImmutable $failedSince): array
    {
        $rows = ChangeRequest::query()
            ->selectRaw('status, count(*) as total')
            ->where(fn ($query) => $query->whereIn('status', [ChangeRequestStatus::PreviewReady->value, ChangeRequestStatus::Approved->value])
                ->orWhere(fn ($q) => $q->where('status', ChangeRequestStatus::Failed->value)->where('updated_at', '>=', $failedSince)))
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = function (ChangeRequestStatus $status) use ($rows): int {
            $value = $rows[$status->value] ?? 0;

            return is_numeric($value) ? (int) $value : 0;
        };

        return [
            'preview_ready' => $count(ChangeRequestStatus::PreviewReady),
            'approved' => $count(ChangeRequestStatus::Approved),
            'failed' => $count(ChangeRequestStatus::Failed),
        ];
    }

    /**
     * @param  array<int, ChangeRequest>  $requests
     * @return list<ChangeRequestData>
     */
    private function map(array $requests): array
    {
        return array_values(array_map(fn (ChangeRequest $r): ChangeRequestData => $r->toData(), $requests));
    }
}
