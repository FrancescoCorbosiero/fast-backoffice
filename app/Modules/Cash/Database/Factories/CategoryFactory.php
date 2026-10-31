<?php

declare(strict_types=1);

namespace App\Modules\Cash\Database\Factories;

use App\Modules\Cash\Enums\CategoryKind;
use App\Modules\Cash\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'kind' => CategoryKind::Expense,
            'is_active' => true,
        ];
    }

    public function income(): static
    {
        return $this->state(fn (): array => ['kind' => CategoryKind::Income]);
    }
}
