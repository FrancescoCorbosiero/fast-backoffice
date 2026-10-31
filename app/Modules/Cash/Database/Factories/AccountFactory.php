<?php

declare(strict_types=1);

namespace App\Modules\Cash\Database\Factories;

use App\Modules\Cash\Enums\AccountType;
use App\Modules\Cash\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Conto '.fake()->unique()->word(),
            'type' => AccountType::Bank,
            'currency' => 'EUR',
            'opening_balance_cents' => 0,
            'opened_on' => '2026-01-01',
            'is_active' => true,
        ];
    }
}
