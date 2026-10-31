<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Cash\Database\Seeders\CashCategoriesSeeder;
use Illuminate\Database\Seeder;

/**
 * Reference data only, idempotent: the owner is created with
 * `owner:create`, and demo data never touches this project
 * (lessons-from-hive-crm.md).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CashCategoriesSeeder::class);
    }
}
