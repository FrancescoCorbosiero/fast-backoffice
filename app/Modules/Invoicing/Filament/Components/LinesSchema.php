<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Components;

use App\Kernel\Money\Money;
use App\Kernel\Money\Quantity;
use App\Kernel\Money\VatRate;
use App\Kernel\Vat\Natura;
use App\Modules\Catalog\Queries\Services;
use App\Modules\Invoicing\Data\LineInput;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use InvalidArgumentException;

/**
 * The lines of a document, with a catalog picker that pre-fills a line.
 * The state keys are the ones LineInput::fromArray reads.
 */
final class LinesSchema
{
    public static function repeater(): Repeater
    {
        $naturaOptions = [];
        foreach (Natura::cases() as $natura) {
            $naturaOptions[$natura->value] = $natura->value.' – '.$natura->label();
        }

        return Repeater::make('lines')
            ->label('Righe')
            ->minItems(1)
            ->defaultItems(1)
            ->columns(12)
            ->addActionLabel('Aggiungi riga')
            ->schema([
                Select::make('service_id')->label('Dal listino')->options(fn (): array => app(Services::class)->options())->searchable()->dehydrated(false)->columnSpan(12)
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $service = is_numeric($state) ? app(Services::class)->find((int) $state) : null;
                        if ($service === null) {
                            return;
                        }
                        foreach ($service->lineTemplate() as $key => $value) {
                            $set($key, $value);
                        }
                        $set('vat_percent', $service->vatRate->toPercentString());
                    })->live(),
                Textarea::make('description')->label('Descrizione')->required()->rows(2)->columnSpan(6)->maxLength(1000),
                TextInput::make('quantity')->label('Quantità')->default('1')->required()->columnSpan(2)->rules([fn (): Closure => self::quantityRule()]),
                TextInput::make('unit_price')->label('Prezzo')->prefix('€')->required()->columnSpan(2)->rules([fn (): Closure => self::moneyRule()]),
                TextInput::make('vat_percent')->label('IVA %')->default('0')->required()->live()->columnSpan(2)->rules([fn (): Closure => self::rateRule()]),
                Select::make('natura')->label('Natura')->options($naturaOptions)->searchable()->columnSpan(12)
                    ->visible(fn (Get $get): bool => self::isZero($get('vat_percent')))
                    ->helperText('Vuoto: usa la natura predefinita delle impostazioni.'),
            ]);
    }

    /**
     * From form state to LineInput arrays: the percent field becomes basis points.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toInput(array $data): array
    {
        $lines = [];
        $raw = $data['lines'] ?? [];

        foreach (is_array($raw) ? $raw : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $percent = $line['vat_percent'] ?? '0';
            $rate = VatRate::fromPercentString(is_scalar($percent) ? (string) $percent : '0');
            $line['vat_basis_points'] = $rate->basisPoints;
            if (! $rate->isZero()) {
                $line['natura'] = null;
            }
            unset($line['vat_percent'], $line['service_id']);
            $lines[] = $line;
        }

        $data['lines'] = $lines;

        return $data;
    }

    /**
     * From stored lines to form state.
     *
     * @param  list<LineInput>  $lines
     * @return list<array<string, mixed>>
     */
    public static function toForm(array $lines): array
    {
        return array_map(fn (LineInput $line): array => [
            ...$line->toArray(),
            'quantity' => rtrim(rtrim($line->quantity->toDecimalString(), '0'), '.'),
            'vat_percent' => $line->vatRate->toPercentString(),
        ], $lines);
    }

    private static function isZero(mixed $percent): bool
    {
        try {
            return VatRate::fromPercentString(is_scalar($percent) ? (string) $percent : '')->isZero();
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function quantityRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                if (Quantity::fromDecimal(is_scalar($value) ? (string) $value : '')->isZero()) {
                    $fail('La quantità non può essere zero.');
                }
            } catch (InvalidArgumentException) {
                $fail('Scrivi una quantità con al massimo quattro decimali.');
            }
        };
    }

    private static function moneyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                Money::fromDecimal(is_scalar($value) ? (string) $value : '');
            } catch (InvalidArgumentException) {
                $fail('Scrivi un importo con al massimo due decimali.');
            }
        };
    }

    private static function rateRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                VatRate::fromPercentString(is_scalar($value) ? (string) $value : '');
            } catch (InvalidArgumentException) {
                $fail('Scrivi una percentuale tra 0 e 100.');
            }
        };
    }
}
