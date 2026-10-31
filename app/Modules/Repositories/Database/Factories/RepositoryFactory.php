<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Database\Factories;

use App\Modules\Repositories\Enums\RepositoryProvider;
use App\Modules\Repositories\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    protected $model = Repository::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = 'site-'.fake()->unique()->numberBetween(1, 99_999);

        return [
            'name' => $slug,
            'url' => 'https://github.com/studio-esempio/'.$slug,
            'provider' => RepositoryProvider::GitHub,
            'default_branch' => 'main',
        ];
    }
}
