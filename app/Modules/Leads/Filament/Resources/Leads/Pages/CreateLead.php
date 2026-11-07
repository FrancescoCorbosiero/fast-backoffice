<?php

declare(strict_types=1);

namespace App\Modules\Leads\Filament\Resources\Leads\Pages;

use App\Modules\Leads\Actions\CreateLead as CreateLeadAction;
use App\Modules\Leads\Data\LeadInput;
use App\Modules\Leads\Filament\Resources\Leads\LeadResource;
use App\Modules\Leads\Models\Lead;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    /**
     * The fields a link may fill in from the query string, so an
     * appointment booked by a prospect becomes a lead in one click.
     */
    private const array PREFILLABLE = ['name', 'company', 'email', 'phone', 'source'];

    protected function fillForm(): void
    {
        parent::fillForm();

        $prefill = [];
        foreach (self::PREFILLABLE as $field) {
            $value = request()->query($field);
            if (is_string($value) && trim($value) !== '') {
                $prefill[$field] = mb_substr(trim($value), 0, 190);
            }
        }

        if ($prefill !== []) {
            $this->form->fillPartially($prefill, array_keys($prefill));
        }
    }

    /**
     * A link to this page with the given fields prefilled; blanks are left out.
     *
     * @param  array<string, string|null>  $fields
     */
    public static function prefilledUrl(array $fields): string
    {
        $fields = array_intersect_key($fields, array_flip(self::PREFILLABLE));

        return static::getUrl(parameters: array_filter($fields, fn (?string $value): bool => $value !== null && trim($value) !== ''));
    }

    protected function handleRecordCreation(array $data): Model
    {
        $lead = app(CreateLeadAction::class)->create(LeadInput::fromArray($data));

        return Lead::query()->findOrFail($lead->id);
    }

    protected function getRedirectUrl(): string
    {
        return LeadResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
