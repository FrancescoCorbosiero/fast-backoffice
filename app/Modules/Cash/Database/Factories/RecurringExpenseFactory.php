<?php

declare(strict_types=1);

namespace App\Modules\Cash\Database\Factories;

use App\Kernel\Dates\Frequency;
use App\Modules\Cash\Models\RecurringExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringExpense>
 */
class RecurringExpenseFactory extends Factory
{
    protected $model = RecurringExpense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Figma',
            'amount_cents' => 5_000,
            'currency' => 'EUR',
            'frequency' => Frequency::Monthly,
            // The anchor is the next due date unless a test says otherwise.
            'next_due_on' => '2026-01-20',
            'started_on' => fn (array $attributes): string => is_string($attributes['next_due_on']) ? $attributes['next_due_on'] : '2026-01-20',
            'is_active' => true,
        ];
    }
}
