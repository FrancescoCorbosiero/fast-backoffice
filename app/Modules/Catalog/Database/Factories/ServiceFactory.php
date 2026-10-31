<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Factories;

use App\Modules\Catalog\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Sviluppo sito web',
            'unit_price_cents' => 150_000,
            'currency' => 'EUR',
            'vat_basis_points' => 2200,
            'natura' => null,
            'is_active' => true,
        ];
    }

    public function flatRate(): static
    {
        return $this->state(fn (): array => ['vat_basis_points' => 0, 'natura' => 'N2.2']);
    }
}
