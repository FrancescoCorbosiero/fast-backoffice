<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Components;

use App\Modules\Contacts\Actions\CreateContact;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Data\ContactInput;
use App\Modules\Contacts\Enums\ContactKind;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Resources\Contacts\Schemas\ContactForm;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Contacts\Queries\SearchContacts;
use App\Support\Filament\FormErrors;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * A field to pick a contact, with search and an inline "new contact"
 * form, for every other module's forms:
 *
 *     ContactPicker::make('contact_id')                          // clients
 *     ContactPicker::make('supplier_id', [ContactRole::Supplier])
 *     ContactPicker::make('contact_id', [])                      // any role; the inline form asks which
 *
 * A contact created inline gets the roles the picker was made with, and
 * the same fiscal fields as the full form, so it can be invoiced. Search
 * results say whether a contact has a fiscal identity, and the chosen
 * contact's page is one click away through the field's suffix.
 */
final class ContactPicker
{
    /**
     * @param  list<ContactRole>  $roles  the roles to search among and to give a contact created inline; empty for any role
     */
    public static function make(string $name, array $roles = [ContactRole::Client]): Select
    {
        $role = count($roles) === 1 ? $roles[0] : null;
        $isCompany = fn (Get $get): bool => $get('kind') === ContactKind::Company->value;

        return Select::make($name)
            ->label('Contatto')
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => self::labels(app(SearchContacts::class)->search($search, $role, 25)))
            ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? app(FindContact::class)->byId((int) $value)?->displayName : null)
            ->suffixAction(
                Action::make('open-contact')
                    ->label('Apri il contatto')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (mixed $state): ?string => is_numeric($state) ? ContactLink::url((int) $state) : null)
                    ->openUrlInNewTab()
                    ->visible(fn (mixed $state): bool => is_numeric($state)),
            )
            ->createOptionForm([
                ContactForm::kindField(),
                ...($roles === [] ? [ContactForm::rolesField()->columns(2)] : []),
                TextInput::make('business_name')->label('Denominazione')->maxLength(80)->visible($isCompany)->required($isCompany),
                TextInput::make('first_name')->label('Nome')->maxLength(60)->hidden($isCompany),
                TextInput::make('last_name')->label('Cognome')->maxLength(60)->hidden($isCompany)->required(fn (Get $get): bool => ! $isCompany($get)),
                ContactForm::emailField(),
                Section::make('Identità fiscale e SdI')
                    ->description('Serve per emettere fatture al contatto.')
                    ->columns(2)
                    ->collapsible()
                    ->components(ContactForm::identityFields()),
            ])
            ->createOptionUsing(function (array $data, Schema $schema) use ($roles): int {
                $input = ContactInput::fromArray($roles === [] ? $data : [...$data, 'roles' => array_map(fn (ContactRole $role): string => $role->value, $roles)]);

                try {
                    return app(CreateContact::class)->create($input)->id;
                } catch (ValidationException $exception) {
                    FormErrors::onSchema($schema, $exception);
                }
            });
    }

    /**
     * "Nome · email · P.IVA 0123…" or "… · senza identità fiscale", so the
     * owner picking an invoice's customer sees who can be invoiced.
     *
     * @param  list<ContactData>  $contacts
     * @return array<int, string>
     */
    private static function labels(array $contacts): array
    {
        $labels = [];
        foreach ($contacts as $contact) {
            $parts = [$contact->displayName];

            if ($contact->email !== null) {
                $parts[] = $contact->email;
            }

            $parts[] = self::fiscalMarker($contact);

            $labels[$contact->id] = implode(' · ', $parts);
        }

        return $labels;
    }

    private static function fiscalMarker(ContactData $contact): string
    {
        if (! $contact->hasFiscalIdentity()) {
            return 'senza identità fiscale';
        }

        return $contact->vatNumber !== null ? 'P.IVA '.$contact->vatNumber->number : 'CF '.$contact->taxCode;
    }
}
