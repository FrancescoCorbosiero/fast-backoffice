<?php

declare(strict_types=1);

namespace App\Modules\Leads\Filament\Components;

use App\Modules\Leads\Filament\Resources\Leads\LeadResource;
use App\Modules\Leads\Filament\Resources\Leads\Pages\CreateLead;

/**
 * Where a lead's pages are, for the modules that start a lead from
 * something they already know (an appointment booked by a prospect).
 */
final class LeadLink
{
    public static function url(int $leadId): string
    {
        return LeadResource::getUrl('view', ['record' => $leadId]);
    }

    /**
     * The create form, with the fields already known filled in.
     *
     * @param  array<string, string|null>  $prefill  name, company, email, phone, source
     */
    public static function createUrl(array $prefill = []): string
    {
        return CreateLead::prefilledUrl($prefill);
    }
}
