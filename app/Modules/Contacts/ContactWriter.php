<?php

declare(strict_types=1);

namespace App\Modules\Contacts;

use App\Modules\Contacts\Data\ContactInput;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Models\Contact;

/**
 * Maps a ContactInput onto the contact's columns, the one place that
 * knows how the input is stored.
 */
final class ContactWriter
{
    /**
     * @return array<string, mixed>
     */
    public static function attributes(ContactInput $input): array
    {
        return [
            'kind' => $input->kind,
            'display_name' => $input->displayName(),
            'first_name' => $input->firstName,
            'last_name' => $input->lastName,
            'business_name' => $input->businessName,
            'email' => $input->email,
            'phone' => $input->phone,
            'vat_country' => $input->vatNumber?->country,
            'vat_number' => $input->vatNumber?->number,
            'tax_code' => $input->taxCode,
            'sdi_code' => $input->sdiCode,
            'pec' => $input->pec,
            ...$input->address->toArray(),
            'roles' => array_map(fn (ContactRole $role): string => $role->value, $input->roles),
            'email_consent' => $input->emailConsent,
            'notes' => $input->notes,
        ];
    }

    public static function liveOrFail(int $id): Contact
    {
        return Contact::query()->live()->findOrFail($id);
    }
}
