<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Resources\Contacts\Schemas;

use App\Kernel\Fiscal\CodiceFiscale;
use App\Kernel\Fiscal\VatNumber;
use App\Modules\Contacts\Enums\ContactKind;
use App\Modules\Contacts\Enums\ContactRole;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use InvalidArgumentException;

/**
 * The contact form. Field names are the keys ContactInput::fromArray reads;
 * the validator in the Contacts module has the last word, these rules just
 * show the errors in place.
 */
class ContactForm
{
    public static function configure(Schema $schema): Schema
    {
        $isCompany = fn (Get $get): bool => $get('kind') === ContactKind::Company->value;

        return $schema->components([
            Section::make('Chi')->columns(2)->components([
                Select::make('kind')->label('Tipo')->options([
                    ContactKind::Individual->value => ContactKind::Individual->label(),
                    ContactKind::Company->value => ContactKind::Company->label(),
                ])->default(ContactKind::Individual->value)->required()->live(),
                CheckboxList::make('roles')->label('Ruoli')->options(ContactRole::options())->columns(2)->required()->default([ContactRole::Client->value]),
                TextInput::make('business_name')->label('Denominazione')->maxLength(80)->visible($isCompany)->required($isCompany)->columnSpanFull(),
                TextInput::make('first_name')->label('Nome')->maxLength(60)->hidden($isCompany),
                TextInput::make('last_name')->label('Cognome')->maxLength(60)->hidden($isCompany)->required(fn (Get $get): bool => ! $isCompany($get)),
                TextInput::make('email')->label('Email')->email()->maxLength(255),
                TextInput::make('phone')->label('Telefono')->tel()->maxLength(30),
            ]),
            Section::make('Identità fiscale e SdI')->columns(2)->components([
                TextInput::make('vat_country')->label('Nazione della partita IVA')->default('IT')->maxLength(2)->regex('/^[A-Za-z]{2}$/')->placeholder('IT'),
                TextInput::make('vat_number')->label('Partita IVA')->maxLength(28)->rules([fn (Get $get): Closure => self::vatRule($get)]),
                TextInput::make('tax_code')->label('Codice fiscale')->maxLength(16)->rules([fn (Get $get): Closure => self::taxCodeRule($get)]),
                TextInput::make('sdi_code')->label('Codice destinatario')->maxLength(7)->regex('/^[A-Za-z0-9]{7}$/')
                    ->helperText('7 caratteri. Vuoto per consumatori e per chi riceve via PEC o sul portale.'),
                TextInput::make('pec')->label('PEC')->email()->maxLength(255)->columnSpanFull(),
            ]),
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
