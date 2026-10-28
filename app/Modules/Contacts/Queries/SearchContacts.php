<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Queries;

use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

final readonly class SearchContacts
{
    /**
     * Name, email, VAT number or tax code containing the term, by name.
     *
     * @return list<ContactData>
     */
    public function search(string $term, ?ContactRole $role = null, int $limit = 50): array
    {
        $term = trim($term);

        $query = Contact::query()->live();

        if ($role !== null) {
            $query->withRole($role);
        }

        $query
            ->when($term !== '', function (Builder $query) use ($term): void {
                $like = '%'.strtolower($term).'%';
                $query->where(function (Builder $query) use ($like): void {
                    $query->whereRaw('lower(display_name) like ?', [$like])
                        ->orWhereRaw('lower(coalesce(email, \'\')) like ?', [$like])
                        ->orWhereRaw('lower(coalesce(vat_number, \'\')) like ?', [$like])
                        ->orWhereRaw('lower(coalesce(tax_code, \'\')) like ?', [$like]);
                });
            })
            ->orderBy('display_name')
            ->limit($limit);

        return array_values($query->get()->map(fn (Contact $contact): ContactData => $contact->toData())->all());
    }

    /**
     * Every live contact with a role, by name.
     *
     * @return list<ContactData>
     */
    public function withRole(ContactRole $role): array
    {
        return array_values(Contact::query()->live()->withRole($role)->orderBy('display_name')->get()
            ->map(fn (Contact $contact): ContactData => $contact->toData())->all());
    }
}
