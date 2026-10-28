<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Queries;

use App\Kernel\Fiscal\VatNumber;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Models\Contact;

final readonly class FindContact
{
    public function byId(int $id): ?ContactData
    {
        return Contact::query()->live()->find($id)?->toData();
    }

    /**
     * Follows merges: the contact a possibly merged id now stands for.
     */
    public function resolve(int $id): ?ContactData
    {
        $contact = Contact::query()->find($id);

        while ($contact !== null && $contact->merged_into_id !== null) {
            $contact = Contact::query()->find($contact->merged_into_id);
        }

        return $contact?->toData();
    }

    /**
     * Many at once, keyed by id; merged and unknown ids are left out.
     *
     * @param  list<int>  $ids
     * @return array<int, ContactData>
     */
    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $contacts = [];
        foreach (Contact::query()->live()->whereIn('id', $ids)->get() as $contact) {
            $contacts[$contact->id] = $contact->toData();
        }

        return $contacts;
    }

    /**
     * The contact a fiscal identity belongs to: VAT number first, then tax code.
     */
    public function byFiscalIdentity(?VatNumber $vatNumber, ?string $taxCode): ?ContactData
    {
        if ($vatNumber !== null) {
            $contact = Contact::query()->live()->where('vat_country', $vatNumber->country)->where('vat_number', $vatNumber->number)->first();

            if ($contact !== null) {
                return $contact->toData();
            }
        }

        if ($taxCode !== null && $taxCode !== '') {
            return Contact::query()->live()->where('tax_code', strtoupper($taxCode))->first()?->toData();
        }

        return null;
    }

    public function byEmail(string $email): ?ContactData
    {
        return Contact::query()->live()->whereRaw('lower(email) = ?', [strtolower(trim($email))])->first()?->toData();
    }
}
