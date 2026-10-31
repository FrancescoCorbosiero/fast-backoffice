<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Leads\Data\LeadData;
use App\Modules\Leads\Data\LeadInput;
use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use Illuminate\Validation\ValidationException;

final readonly class CreateLead
{
    /**
     * @throws ValidationException
     */
    public function create(LeadInput $input): LeadData
    {
        self::validate($input);

        return Lead::query()->create([...self::attributes($input), 'status' => LeadStatus::New])->toData();
    }

    /**
     * @throws ValidationException
     */
    public static function validate(LeadInput $input): void
    {
        $errors = [];
        if ($input->name === '') {
            $errors['name'] = 'Chi è il contatto?';
        }
        if ($input->email !== null && filter_var($input->email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Non è un indirizzo email valido.';
        }
        if ($input->estimatedValue !== null && $input->estimatedValue->isNegative()) {
            $errors['estimated_value'] = 'Il valore non può essere negativo.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function attributes(LeadInput $input): array
    {
        return [
            'name' => $input->name,
            'company' => $input->company,
            'email' => $input->email,
            'phone' => $input->phone,
            'source' => $input->source,
            'estimated_value_cents' => $input->estimatedValue?->cents,
            'currency' => $input->estimatedValue->currency ?? 'EUR',
            'next_follow_up_on' => $input->nextFollowUpOn?->toDateString(),
            'notes' => $input->notes,
        ];
    }
}
