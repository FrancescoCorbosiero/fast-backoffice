<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Leads\Data\LeadData;
use App\Modules\Leads\Data\LeadInput;
use App\Modules\Leads\Models\Lead;
use Illuminate\Validation\ValidationException;

final readonly class UpdateLead
{
    /**
     * @throws ValidationException
     */
    public function update(int $leadId, LeadInput $input): LeadData
    {
        CreateLead::validate($input);
        $lead = Lead::query()->findOrFail($leadId);
        $lead->fill(CreateLead::attributes($input))->save();

        return $lead->toData();
    }
}
