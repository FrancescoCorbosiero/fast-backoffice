<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Audit\Actions\RecordAuditEntry;
use App\Modules\Leads\Data\LeadData;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Moves a lead along the pipeline. Won only through ConvertLead; lost
 * keeps the reason; a lost lead can be reopened.
 */
final readonly class MoveLead
{
    public function __construct(private RecordAuditEntry $audit) {}

    /**
     * @throws LogicException when the move needs a conversion, or the lead is won
     */
    public function move(int $leadId, LeadStatus $status, ?string $lostReason = null, ?CarbonImmutable $nextFollowUpOn = null): LeadData
    {
        $lead = Lead::query()->findOrFail($leadId);

        if ($status === LeadStatus::Won) {
            throw new LogicException('A lead is won by converting it.');
        }
        if ($lead->status === LeadStatus::Won) {
            throw new LogicException("Lead {$leadId} was converted: it stays won.");
        }

        $lead->forceFill([
            'status' => $status,
            'lost_reason' => $status === LeadStatus::Lost ? ($lostReason === null || trim($lostReason) === '' ? null : mb_substr(trim($lostReason), 0, 500)) : null,
            'next_follow_up_on' => $status->isOpen() ? ($nextFollowUpOn?->toDateString() ?? $lead->next_follow_up_on?->toDateString()) : null,
        ])->save();

        $this->audit->record('leads.lead.moved', $lead->toData()->reference(), ['status' => $status->value, 'reason' => $lead->lost_reason]);

        return $lead->toData();
    }
}
