<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Widgets;

use App\Modules\Cash\Enums\Direction;

class ExpenseCategoriesWidget extends CategoriesWidget
{
    protected static ?int $sort = 4;

    protected static ?string $heading = 'Uscite per categoria, anno in corso';

    protected function direction(): Direction
    {
        return Direction::Out;
    }
}
