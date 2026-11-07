<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Resources\Contacts\Schemas;

use App\Kernel\Fiscal\CodiceFiscale;
use App\Kernel\Fiscal\VatNumber;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Enums\ContactKind;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Queries\FindContact;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/**
 * The contact form. Field names are the keys ContactInput::fromArray reads;
 * the validator in the Contacts module has the last word, these rules just
 * show the errors in place. Duplicates are hinted at while typing (same
 * email, same VAT number), with a link to the contact that already exists,
 * so the merge tool has less to clean up.
 */
class ContactForm
{
    public static function configure(Schema $schema): Schema
    {
        $isCompany = fn (Get $get): bool => $get('kind') === ContactKind::Company->value;

        return $schema->components([
            Section::make('Chi')->columns(2)->components([
                self::kindField(),
                self::rolesField()->columns(2),
                TextInput::make('business_name')->label('Denominazione')->maxLength(80)->visible($isCompany)->required($isCompany)->columnSpanFull(),
                TextInput::make('first_name')->label('Nome')->maxLength(60)->hidden($isCompany),
                TextInput::make('last_name')->label('Cognome')->maxLength(60)->hidden($isCompany)->required(fn (Get $get): bool => ! $isCompany($get)),
                self::emailField(),
                TextInput::make('phone')->label('Telefono')->tel()->maxLength(30),
            ]),
            Section::make('Identità fiscale e SdI')->columns(2)->components(self::identityFields()),
            Section::make('Indirizzo')->columns(3)->components([
                TextInput::make('street')->label('Indirizzo')->maxLength(60)->columnSpan(2),
                TextInput::make('street_number')->label('Civico')->maxLength(8),
                TextInput::make('postcode')->label('CAP')->maxLength(10),
                TextInput::make('city')->label('Comune')->maxLength(60),
                TextInput::make('province')->label('Provincia')->maxLength(2)->regex('/^[A-Za-z]{2}$/'),
                TextInput::make('country')->label('Nazione')->default('IT')->maxLength(2)->regex('/^[A-Za-z]{2}$/')->required(),
            ]),
            Section::make('Email e note')->components([
                Toggle::make('email_consent')->label('Ha acconsentito a ricevere le email dello studio'),
                Textarea::make('notes')->label('Note')->rows(3)->maxLength(5000),
            ]),
        ]);
    }

    public static function kindField(): Select
    {
        return Select::make('kind')->label('Tipo')->options([
            ContactKind::Individual->value => ContactKind::Individual->label(),
            ContactKind::Company->value => ContactKind::Company->label(),
        ])->default(ContactKind::Individual->value)->required()->live();
    }

    public static function rolesField(): CheckboxList
    {
        return CheckboxList::make('roles')->label('Ruoli')->options(ContactRole::options())->required()->default([ContactRole::Client->value]);
    }

    /**
     * The email, hinting at the contact that already has it.
     */
    public static function emailField(): TextInput
    {
        return TextInput::make('email')->label('Email')->email()->maxLength(255)->live(onBlur: true)
            ->hint(fn (Get $get, ?Contact $record): ?string => ($other = self::existingByEmail($get, $record)) !== null ? 'Esiste già: '.$other->displayName : null)
            ->hintColor('warning')
            ->hintAction(self::openExistingAction('open-by-email', self::existingByEmail(...)));
    }

    /**
     * Partita IVA, codice fiscale, codice destinatario and PEC with their
     * in-place rules: the full form and the pickers' inline form share them,
     * so a contact created from an invoice has an SdI address too.
     *
     * @return list<Component>
     */
    public static function identityFields(): array
    {
        return [
            TextInput::make('vat_country')->label('Nazione della partita IVA')->default('IT')->maxLength(2)->regex('/^[A-Za-z]{2}$/')->placeholder('IT'),
            TextInput::make('vat_number')->label('Partita IVA')->maxLength(28)->live(onBlur: true)->rules([fn (Get $get): Closure => self::vatRule($get)])
                ->hint(fn (Get $get, ?Contact $record): ?string => ($other = self::existingByVat($get, $record)) !== null ? 'Un altro contatto ha già questa partita IVA: '.$other->displayName : null)
                ->hintColor('warning')
                ->hintAction(self::openExistingAction('open-by-vat', self::existingByVat(...))),
            TextInput::make('tax_code')->label('Codice fiscale')->maxLength(16)->rules([fn (Get $get): Closure => self::taxCodeRule($get)]),
            TextInput::make('sdi_code')->label('Codice destinatario')->maxLength(7)->regex('/^[A-Za-z0-9]{7}$/')
                ->helperText('7 caratteri. Vuoto per consumatori e per chi riceve via PEC o sul portale.'),
            TextInput::make('pec')->label('PEC')->email()->maxLength(255)->columnSpanFull(),
        ];
    }

    /**
     * @param  Closure(Get, ?Contact): ?ContactData  $find
     */
    private static function openExistingAction(string $name, Closure $find): Action
    {
        return Action::make($name)
            ->label('Apri')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->url(fn (Get $get, ?Contact $record): ?string => ($other = $find($get, $record)) !== null ? ContactLink::url($other->id) : null)
            ->openUrlInNewTab()
            ->visible(fn (Get $get, ?Contact $record): bool => $find($get, $record) !== null);
    }

    private static function existingByEmail(Get $get, ?Contact $record): ?ContactData
    {
        $email = $get('email');

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        $other = app(FindContact::class)->byEmail($email);

        return $other === null || $other->id === $record?->id ? null : $other;
    }

    private static function existingByVat(Get $get, ?Contact $record): ?ContactData
    {
        $value = $get('vat_number');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $country = $get('vat_country');

        try {
            $vat = VatNumber::parse($value, is_string($country) && $country !== '' ? $country : 'IT');
        } catch (InvalidArgumentException) {
            return null;
        }

        $other = app(FindContact::class)->byFiscalIdentity($vat, null);

        return $other === null || $other->id === $record?->id ? null : $other;
    }

    private static function vatRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $country = $get('vat_country');

            try {
                $vat = VatNumber::parse($value, is_string($country) && $country !== '' ? $country : 'IT');
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());

                return;
            }

            if (! $vat->isValid()) {
                $fail($vat->isItalian() ? 'La partita IVA non è valida.' : "Non ha il formato delle partite IVA di {$vat->country}.");
            }
        };
    }

    private static function taxCodeRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $country = $get('vat_country');

            if (is_string($country) && strtoupper($country) !== 'IT' && filled($get('vat_number'))) {
                return;
            }

            if (! CodiceFiscale::isValid($value)) {
                $fail('Il codice fiscale non è valido.');
            }
        };
    }
}
