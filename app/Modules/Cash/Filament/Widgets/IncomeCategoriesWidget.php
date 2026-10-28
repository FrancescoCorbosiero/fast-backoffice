<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Widgets;

use App\Modules\Cash\Enums\Direction;

class IncomeCategoriesWidget extends CategoriesWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'Entrate per categoria, anno in corso';

    protected function direction(): Direction
    {
        return Direction::In;
    }
}
