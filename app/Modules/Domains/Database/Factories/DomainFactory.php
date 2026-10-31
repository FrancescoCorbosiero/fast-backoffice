<?php

declare(strict_types=1);

namespace App\Modules\Domains\Database\Factories;

use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'bellavista-'.fake()->unique()->numberBetween(1, 99_999).'.example',
            'contact_id' => null, // the test supplies a contact from the Contacts module
            'registrar' => 'Registrar Esempio',
            'status' => DomainStatus::Active,
            'registered_on' => '2024-03-31',
            'expires_on' => '2026-03-31',
            'renewal_months' => 12,
            'auto_renew' => true,
            'renewal_cost_cents' => 1_500,
        ];
    }
}
