<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Database\Factories;

use App\Modules\Invoicing\Enums\DocumentType;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Drafts only: issued documents come from IssueInvoice, which is the only
 * way a number is allocated.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => DocumentType::Invoice,
            'status' => InvoiceStatus::Draft,
            'series' => 'invoices',
            'contact_id' => null, // the test supplies a contact from the Contacts module
            'customer_name' => 'Cliente',
            'currency' => 'EUR',
            'lines' => [
                ['description' => 'Sviluppo sito', 'quantity' => '1.0000', 'unit_price' => '1000.00', 'vat_basis_points' => 0, 'natura' => 'N2.2'],
            ],
            'payment_terms_days' => 30,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function withLines(array $lines): static
    {
        return $this->state(fn (): array => ['lines' => $lines]);
    }

    public function taxed(): static
    {
        return $this->withLines([
            ['description' => 'Sviluppo sito', 'quantity' => '1.0000', 'unit_price' => '1000.00', 'vat_basis_points' => 2200, 'natura' => null],
        ]);
    }
}
