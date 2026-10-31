<?php

declare(strict_types=1);

namespace App\Modules\Cash\Database\Seeders;

use App\Modules\Cash\Enums\CategoryKind;
use App\Modules\Cash\Models\Category;
use Illuminate\Database\Seeder;

/**
 * A starting set of categories. Idempotent: existing names are kept.
 */
class CashCategoriesSeeder extends Seeder
{
    private const array CATEGORIES = [
        'income' => ['Sviluppo web', 'Abbonamenti siti', 'Consulenza', 'Hosting e domini rivenduti', 'Altre entrate'],
        'expense' => ['Hosting', 'Domini', 'Software', 'Hardware', 'Collaboratori', 'Tasse e contributi', 'Commissioni bancarie', 'Formazione', 'Altre uscite'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $kind => $names) {
            foreach ($names as $sort => $name) {
                Category::query()->firstOrCreate(['kind' => CategoryKind::from($kind), 'name' => $name], ['sort' => $sort]);
            }
        }
    }
}
