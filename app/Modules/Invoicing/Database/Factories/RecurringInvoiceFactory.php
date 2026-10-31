<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Database\Factories;

use App\Kernel\Dates\Frequency;
use App\Modules\Invoicing\Models\RecurringInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringInvoice>
 */
class RecurringInvoiceFactory extends Factory
{
    protected $model = RecurringInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Canone sito',
            'contact_id' => null, // the test supplies a contact from the Contacts module
            'frequency' => Frequency::Monthly,
            'next_issue_on' => '2026-02-01',
            'started_on' => fn (array $attributes): string => is_string($attributes['next_issue_on']) ? $attributes['next_issue_on'] : '2026-02-01',
            'lines' => [
                ['description' => 'Canone mensile', 'quantity' => '1.0000', 'unit_price' => '100.00', 'vat_basis_points' => 0, 'natura' => 'N2.2'],
            ],
            'payment_terms_days' => 30,
            'auto_issue' => false,
            'is_active' => true,
        ];
    }
}
