<?php

declare(strict_types=1);

namespace App\Modules\Settings\Filament\Pages;

use App\Kernel\Numbering\NumberFormat;
use App\Kernel\Numbering\NumberSequence;
use App\Modules\Settings\Actions\SaveNumberingSeries;
use App\Modules\Settings\Data\NumberingSeries;
use App\Modules\Settings\Queries\GetNumberingSeries;
use App\Support\Shape;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class NumberingSeriesPage extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static ?string $navigationLabel = 'Numerazione';

    protected static ?string $title = 'Serie di numerazione';

    protected static ?string $slug = 'settings/numbering';

    protected static ?int $navigationSort = 3;

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)->components([
            Section::make('Serie')
                ->description('Il formato usa {number}, {number:4} (con zeri), {year} e {yy}: lo stesso numero appare nell\'XML, nel PDF e a schermo. Il numero di partenza serve quando la numerazione dell\'anno è iniziata con un altro strumento.')
                ->components([
                    Repeater::make('series')->hiddenLabel()->minItems(1)->reorderable(false)->columns(4)->schema([
                        TextInput::make('code')->label('Codice')->required()->regex('/^[a-z][a-z0-9_-]{0,49}$/')->distinct(),
                        TextInput::make('label')->label('Nome')->required()->maxLength(60),
                        TextInput::make('format')->label('Formato')->required()->default('{number}/{year}')->rules([fn (): Closure => self::formatRule()]),
                        TextEntry::make('last_number')
                            ->label('Ultimo numero del '.CarbonImmutable::today()->year)
                            ->state(fn (Get $get): string => self::lastNumber($get('code')))
                            ->helperText('Emesso da questa app: dice quale serie è in uso.'),
                        Repeater::make('opening_numbers')->label('Numeri di partenza')->columns(2)->columnSpanFull()->defaultItems(0)->schema([
                            TextInput::make('year')->label('Anno')->integer()->minValue(2000)->maxValue(2200)->required(),
                            TextInput::make('number')->label('Primo numero emesso da questa app')->integer()->minValue(1)->required(),
                        ]),
                    ]),
                ]),
        ]);
    }

    protected function load(): array
    {
        $series = [];
        foreach (app(GetNumberingSeries::class)->get()->series as $item) {
            $openings = [];
            foreach ($item->openingNumbers as $year => $number) {
                $openings[] = ['year' => $year, 'number' => $number];
            }
            $series[] = [...$item->toArray(), 'opening_numbers' => $openings];
        }

        return ['series' => $series];
    }

    protected function persist(array $state): void
    {
        $series = [];
        foreach (Shape::listOfArrays($state, 'series') as $row) {
            $openings = [];
            foreach (Shape::listOfArrays($row, 'opening_numbers') as $opening) {
                $openings[Shape::int($opening, 'year')] = Shape::int($opening, 'number');
            }
            $series[] = [...$row, 'opening_numbers' => $openings];
        }

        app(SaveNumberingSeries::class)->save(NumberingSeries::fromArray(['series' => $series]));
    }

    /**
     * The last number the Kernel allocated in this series this year.
     */
    private static function lastNumber(mixed $code): string
    {
        if (! is_string($code) || preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $code) !== 1) {
            return '—';
        }

        $last = app(NumberSequence::class)->last($code, CarbonImmutable::today()->year);

        return $last === null ? 'nessuno' : (string) $last;
    }

    private static function formatRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                new NumberFormat(is_string($value) ? $value : '');
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
