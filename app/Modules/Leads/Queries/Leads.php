<?php

declare(strict_types=1);

namespace App\Modules\Leads\Queries;

use App\Modules\Leads\Data\LeadData;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;

final readonly class Leads
{
    public function find(int $id): ?LeadData
    {
        return Lead::query()->find($id)?->toData();
    }

    /**
     * @return list<LeadData> oldest first
     */
    public function open(): array
    {
        return $this->map(Lead::query()->open()->orderBy('created_at')->orderBy('id')->get()->all());
    }

    /**
     * Open leads whose follow-up is on or before today, oldest follow-up first.
     *
     * @return list<LeadData>
     */
    public function followUpsDue(CarbonImmutable $today): array
    {
        return $this->map(Lead::query()->open()->whereNotNull('next_follow_up_on')->where('next_follow_up_on', '<=', $today->startOfDay()->toDateString())->orderBy('next_follow_up_on')->orderBy('id')->get()->all());
    }

    /**
     * How many leads sit in each status, in one query.
     *
     * @return array<string, int> status value → count
     */
    public function pipeline(): array
    {
        $counts = [];
        foreach (LeadStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        foreach (Lead::query()->selectRaw('status, count(*) as n')->groupBy('status')->get() as $row) {
            $status = $row->getAttribute('status');
            $n = $row->getAttribute('n');
            if ($status instanceof LeadStatus && is_numeric($n)) {
                $counts[$status->value] = (int) $n;
            }
        }

        return $counts;
    }

    /**
     * @return list<LeadData>
     */
    public function forContact(int $contactId): array
    {
        return $this->map(Lead::query()->where('contact_id', $contactId)->orderByDesc('id')->get()->all());
    }

    /**
     * @param  array<int, Lead>  $leads
     * @return list<LeadData>
     */
    private function map(array $leads): array
    {
        return array_values(array_map(fn (Lead $lead): LeadData => $lead->toData(), $leads));
    }
}
