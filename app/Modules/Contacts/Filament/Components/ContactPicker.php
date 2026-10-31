<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Components;

use App\Modules\Contacts\Actions\CreateContact;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Data\ContactInput;
use App\Modules\Contacts\Enums\ContactKind;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Contacts\Queries\SearchContacts;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * A field to pick a contact, with search and an inline "new contact"
 * form, for every other module's forms:
 *
 *     ContactPicker::make('contact_id')                          // clients
 *     ContactPicker::make('supplier_id', [ContactRole::Supplier])
 *
 * A contact created inline gets the roles the picker was made with.
 */
final class ContactPicker
{
    /**
     * @param  list<ContactRole>  $roles  the roles to search among and to give a contact created inline
     */
    public static function make(string $name, array $roles = [ContactRole::Client]): Select
    {
        $role = count($roles) === 1 ? $roles[0] : null;

        return Select::make($name)
            ->label('Contatto')
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => self::labels(app(SearchContacts::class)->search($search, $role, 25)))
            ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? app(FindContact::class)->byId((int) $value)?->displayName : null)
            ->createOptionForm([
                Select::make('kind')->label('Tipo')->options([
                    ContactKind::Individual->value => ContactKind::Individual->label(),
                    ContactKind::Company->value => ContactKind::Company->label(),
                ])->default(ContactKind::Individual->value)->required()->live(),
                TextInput::make('business_name')->label('Denominazione')->maxLength(80)
                    ->visible(fn (Get $get): bool => $get('kind') === ContactKind::Company->value)
                    ->required(fn (Get $get): bool => $get('kind') === ContactKind::Company->value),
                TextInput::make('first_name')->label('Nome')->maxLength(60)
                    ->visible(fn (Get $get): bool => $get('kind') !== ContactKind::Company->value),
                TextInput::make('last_name')->label('Cognome')->maxLength(60)
                    ->visible(fn (Get $get): bool => $get('kind') !== ContactKind::Company->value)
                    ->required(fn (Get $get): bool => $get('kind') !== ContactKind::Company->value),
                TextInput::make('email')->label('Email')->email()->maxLength(255),
                TextInput::make('vat_number')->label('Partita IVA')->maxLength(28),
            ])
            ->createOptionUsing(function (array $data) use ($roles): int {
                $input = ContactInput::fromArray([...$data, 'roles' => array_map(fn (ContactRole $role): string => $role->value, $roles)]);

                return app(CreateContact::class)->create($input)->id;
            });
    }

    /**
     * @param  list<ContactData>  $contacts
     * @return array<int, string>
     */
    private static function labels(array $contacts): array
    {
        $labels = [];
        foreach ($contacts as $contact) {
            $labels[$contact->id] = $contact->displayName.($contact->email !== null ? " · {$contact->email}" : '');
        }

        return $labels;
    }
}
