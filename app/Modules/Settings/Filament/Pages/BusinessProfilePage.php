<?php

declare(strict_types=1);

namespace App\Modules\Settings\Filament\Pages;

use App\Modules\Settings\Actions\SaveBusinessProfile;
use App\Modules\Settings\Data\BusinessProfile;
use App\Modules\Settings\Enums\ProfileType;
use App\Modules\Settings\Enums\RegimeFiscale;
use App\Modules\Settings\Queries\GetBusinessProfile;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class BusinessProfilePage extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Profilo aziendale';

    protected static ?string $title = 'Profilo aziendale';

    protected static ?string $slug = 'settings/business-profile';

    protected static ?int $navigationSort = 1;

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)->components([
            Section::make('Identità fiscale')->description('Il cedente/prestatore di ogni fattura.')->columns(2)->components([
                Select::make('type')->label('Tipo')->options(self::options(ProfileType::cases()))->required()->live(),
                Select::make('regime')->label('Regime fiscale')->options(self::options(RegimeFiscale::cases()))->required(),
                TextInput::make('business_name')->label('Denominazione')->maxLength(80)
                    ->required(fn (Get $get): bool => $get('type') === ProfileType::Company->value),
                TextInput::make('first_name')->label('Nome')->maxLength(60)
                    ->hidden(fn (Get $get): bool => $get('type') === ProfileType::Company->value),
                TextInput::make('last_name')->label('Cognome')->maxLength(60)
                    ->hidden(fn (Get $get): bool => $get('type') === ProfileType::Company->value),
                TextInput::make('vat_number')->label('Partita IVA')->maxLength(11)->regex('/^\d{11}$/'),
                TextInput::make('tax_code')->label('Codice fiscale')->maxLength(16)->regex('/^[A-Za-z0-9]{11,16}$/'),
            ]),
            Section::make('Sede')->columns(3)->components([
                TextInput::make('street')->label('Indirizzo')->maxLength(60)->columnSpan(2),
                TextInput::make('street_number')->label('Civico')->maxLength(8),
                TextInput::make('postcode')->label('CAP')->maxLength(5)->regex('/^\d{5}$/'),
                TextInput::make('city')->label('Comune')->maxLength(60),
                TextInput::make('province')->label('Provincia')->maxLength(2)->regex('/^[A-Za-z]{2}$/'),
                TextInput::make('country')->label('Nazione')->maxLength(2)->regex('/^[A-Za-z]{2}$/')->default('IT')->required(),
            ]),
            Section::make('Contatti e pagamenti')->columns(2)->components([
                TextInput::make('email')->label('Email')->email()->maxLength(256),
                TextInput::make('pec')->label('PEC')->email()->maxLength(256),
                TextInput::make('phone')->label('Telefono')->tel()->maxLength(20),
                TextInput::make('iban')->label('IBAN')->maxLength(34)->regex('/^[A-Za-z]{2}\d{2}[A-Za-z0-9 ]{10,30}$/'),
            ]),
        ]);
    }

    protected function load(): array
    {
        return app(GetBusinessProfile::class)->get()->toArray();
    }

    protected function persist(array $state): void
    {
        app(SaveBusinessProfile::class)->save(BusinessProfile::fromArray($state));
    }

    /**
     * @param  list<ProfileType|RegimeFiscale>  $cases
     * @return array<string, string>
     */
    private static function options(array $cases): array
    {
        $options = [];
        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
