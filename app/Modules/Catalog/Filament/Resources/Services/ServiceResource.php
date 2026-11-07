<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Filament\Resources\Services;

use App\Kernel\Dates\Frequency;
use App\Kernel\Money\Money;
use App\Kernel\Money\VatRate;
use App\Kernel\Vat\Natura;
use App\Modules\Catalog\Filament\Resources\Services\Pages\ManageServices;
use App\Modules\Catalog\Models\Service;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use UnitEnum;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Fatturazione';

    protected static ?int $navigationSort = 50;

    protected static ?string $modelLabel = 'servizio';

    protected static ?string $pluralModelLabel = 'Listino';

    protected static ?string $slug = 'catalog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::fields());
    }

    /**
     * The fields of a service, for the resource's own form and for the
     * picker's inline «new service» form. The order in the listino is
     * set by dragging the rows, not here.
     *
     * @return list<Component>
     */
    public static function fields(): array
    {
        $naturaOptions = [];
        foreach (Natura::cases() as $natura) {
            $naturaOptions[$natura->value] = $natura->value.' – '.$natura->label();
        }
        $cadences = [];
        foreach (Frequency::cases() as $frequency) {
            $cadences[$frequency->value] = $frequency->label();
        }

        return [
            TextInput::make('name')->label('Nome')->required()->maxLength(120),
            Textarea::make('description')->label('Descrizione sul documento')->rows(2)->maxLength(1000),
            TextInput::make('unit_price')->label('Prezzo unitario')->prefix('€')->required()
                ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        Money::fromDecimal(is_scalar($value) ? (string) $value : '');
                    } catch (InvalidArgumentException) {
                        $fail('Scrivi un importo con al massimo due decimali.');
                    }
                }]),
            TextInput::make('vat_percent')->label('IVA %')->required()->default('22')->live()
                ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        VatRate::fromPercentString(is_scalar($value) ? (string) $value : '');
                    } catch (InvalidArgumentException) {
                        $fail('Scrivi una percentuale tra 0 e 100.');
                    }
                }]),
            Select::make('natura')->label('Natura (per le righe senza IVA)')->options($naturaOptions)->searchable()
                ->visible(fn (Get $get): bool => self::isZero($get('vat_percent')))
                ->required(fn (Get $get): bool => self::isZero($get('vat_percent'))),
            Select::make('cadence')->label('Cadenza')->options($cadences)->placeholder('Una tantum'),
            Toggle::make('is_active')->label('Attivo')->default(true),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->weight('bold'),
                TextColumn::make('unit_price_cents')->label('Prezzo')->alignEnd()->formatStateUsing(fn (Service $record): string => $record->toData()->unitPrice->format()),
                TextColumn::make('vat_basis_points')->label('IVA')->formatStateUsing(fn (Service $record): string => $record->natura !== null ? $record->natura->value : (new VatRate($record->vat_basis_points))->toPercentString().'%'),
                TextColumn::make('cadence')->label('Cadenza')->placeholder('una tantum')->formatStateUsing(fn (Frequency $state): string => $state->label()),
                IconColumn::make('is_active')->label('Attivo')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Attivo')->default(true),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data): array => self::toForm($data))
                    ->mutateDataUsing(fn (array $data): array => self::toAttributes($data)),
            ])
            ->paginated([25, 50]);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Where a new service lands in the listino: after the others.
     */
    public static function nextSort(): int
    {
        $max = Service::query()->max('sort');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    /**
     * From the form's fields to the service's columns: the price and the
     * rate as people write them become cents and basis points.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toAttributes(array $data): array
    {
        $rate = VatRate::fromPercentString(is_scalar($data['vat_percent'] ?? null) ? (string) $data['vat_percent'] : '0');

        return [
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'unit_price_cents' => Money::fromDecimal(is_scalar($data['unit_price'] ?? null) ? (string) $data['unit_price'] : '0')->cents,
            'currency' => 'EUR',
            'vat_basis_points' => $rate->basisPoints,
            'natura' => $rate->isZero() ? ($data['natura'] ?? null) : null,
            'cadence' => $data['cadence'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function toForm(array $data): array
    {
        $data['unit_price'] = (new Money(is_numeric($data['unit_price_cents'] ?? null) ? (int) $data['unit_price_cents'] : 0))->toDecimalString();
        $data['vat_percent'] = (new VatRate(is_numeric($data['vat_basis_points'] ?? null) ? (int) $data['vat_basis_points'] : 0))->toPercentString();

        return $data;
    }

    private static function isZero(mixed $percent): bool
    {
        try {
            return VatRate::fromPercentString(is_scalar($percent) ? (string) $percent : '')->isZero();
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageServices::route('/'),
        ];
    }
}
