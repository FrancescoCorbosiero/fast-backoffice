<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Database\Factories;

use App\Modules\Invoicing\Enums\DocumentType;
use App\Modules\Invoicing\Models\SupplierInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierInvoice>
 */
class SupplierInvoiceFactory extends Factory
{
    protected $model = SupplierInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => null, // the test supplies a contact from the Contacts module
            'supplier_name' => 'Fornitore Srl',
            'fingerprint' => fake()->unique()->uuid(),
            'type' => DocumentType::Invoice,
            'number' => '42',
            'issued_on' => '2026-04-01',
            'currency' => 'EUR',
            'lines' => [['description' => 'Dominio', 'quantity' => '1.0000', 'unit_price' => '12.00', 'vat_basis_points' => 2200, 'natura' => null]],
            'totals' => ['subtotal_cents' => 1200, 'inps_cents' => 0, 'inps_basis_points' => 0, 'summary' => [['vat_basis_points' => 2200, 'natura' => null, 'taxable_cents' => 1200, 'vat_cents' => 264]], 'vat_cents' => 264, 'stamp_duty_due' => false, 'stamp_duty_charged_cents' => 0, 'total_cents' => 1464],
            'total_cents' => 1464,
            'received_at' => now(),
        ];
    }
}
