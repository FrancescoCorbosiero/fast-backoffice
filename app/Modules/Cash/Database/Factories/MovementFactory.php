<?php

declare(strict_types=1);

namespace App\Modules\Cash\Database\Factories;

use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Models\Account;
use App\Modules\Cash\Models\Movement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Movement>
 */
class MovementFactory extends Factory
{
    protected $model = Movement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'direction' => Direction::In,
            'amount_cents' => 10_000,
            'currency' => 'EUR',
            'occurred_on' => '2026-03-15',
            'description' => fake()->sentence(3),
            'is_taxable' => true,
        ];
    }

    public function in(int $cents, string $on = '2026-03-15'): static
    {
        return $this->state(fn (): array => ['direction' => Direction::In, 'amount_cents' => $cents, 'occurred_on' => $on]);
    }

    public function out(int $cents, string $on = '2026-03-15'): static
    {
        return $this->state(fn (): array => ['direction' => Direction::Out, 'amount_cents' => $cents, 'occurred_on' => $on]);
    }

    public function allocatedTo(string $reference): static
    {
        return $this->state(fn (): array => ['allocation' => $reference]);
    }
}
