<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Widgets;

use App\Modules\Cash\Data\CategoryTotal;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Filament\Amounts;
use App\Modules\Cash\Queries\Analytics;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The year's totals per category, one direction per widget.
 */
abstract class CategoriesWidget extends TableWidget
{
    abstract protected function direction(): Direction;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('name')->label('Categoria'),
                TextColumn::make('movements')->label('Movimenti')->alignEnd(),
                TextColumn::make('total')->label('Totale')->alignEnd()->weight('bold'),
            ])
            ->emptyStateHeading('Nessun movimento quest\'anno')
            ->paginated(false);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $today = CarbonImmutable::today();
        $rows = [];

        foreach (app(Analytics::class)->byCategory($this->direction(), $today->startOfYear(), $today) as $index => $total) {
            $rows['category-'.$index] = self::row($total);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(CategoryTotal $total): array
    {
        return ['name' => $total->name, 'movements' => $total->movements, 'total' => Amounts::format($total->total)];
    }
}
