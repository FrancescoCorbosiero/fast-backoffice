<?php

declare(strict_types=1);

namespace App\Modules\Leads\Contributions;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Leads\Filament\Components\LeadLink;
use App\Modules\Leads\Queries\Leads;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

/**
 * Where the client came from: the lead that became this contact, with
 * its source, estimated value and dates. Nothing to show for a contact
 * that never was a lead, so the section stays hidden then.
 */
final class LeadsContactPanel implements ContactPanel
{
    public function key(): string
    {
        return 'leads';
    }

    public function label(): string
    {
        return 'Da lead';
    }

    public function sort(): int
    {
        return 60;
    }

    public function component(int $contactId): Component
    {
        $lead = app(Leads::class)->forContact($contactId)[0] ?? null;

        if ($lead === null) {
            return Section::make($this->label())->hidden();
        }

        return Section::make($this->label())->compact()->columns(4)->components([
            TextEntry::make('l_lead')->label('Lead')->state($lead->title())->url(LeadLink::url($lead->id)),
            TextEntry::make('l_status')->label('Stato')->state($lead->status->label())->badge()->color($lead->status->color()),
            TextEntry::make('l_source')->label('Fonte')->state($lead->source ?? '—'),
            TextEntry::make('l_value')->label('Valore stimato')->state($lead->estimatedValue?->format() ?? '—'),
            TextEntry::make('l_converted')->label('Convertito il')->state($lead->convertedAt?->isoFormat('D MMM YYYY') ?? '—'),
        ]);
    }

    /**
     * A lead is not created from its contact: the contact is what the
     * lead becomes.
     */
    public function createAction(int $contactId): ?Action
    {
        return null;
    }
}
