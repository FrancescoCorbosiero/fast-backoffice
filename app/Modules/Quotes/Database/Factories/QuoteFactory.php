<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Database\Factories;

use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $line = ['description' => 'Sito vetrina', 'quantity' => '1.0000', 'unit_price' => '1500.00', 'vat_basis_points' => 0, 'natura' => 'N2.2'];

        return [
            'year' => 2026,
            'number' => fn (array $attributes): int => self::nextNumber(is_int($attributes['year']) ? $attributes['year'] : 2026),
            'number_text' => fn (array $attributes): string => sprintf('PRV %d/%d', is_int($attributes['number']) ? $attributes['number'] : 1, is_int($attributes['year']) ? $attributes['year'] : 2026),
            'status' => QuoteStatus::Draft,
            'issued_on' => '2026-03-01',
            'valid_until' => '2026-03-31',
            'contact_id' => null, // the test supplies a contact from the Contacts module
            'customer_name' => 'Cliente',
            'subject' => 'Sito vetrina',
            'lines' => [$line],
            'totals' => ['subtotal_cents' => 150_000, 'inps_cents' => 0, 'inps_basis_points' => 0, 'inps_vat_basis_points' => null, 'inps_natura' => null, 'summary' => [], 'vat_cents' => 0, 'stamp_duty_due' => false, 'stamp_duty_charged_cents' => 0, 'total_cents' => 150_000],
            'total_cents' => 150_000,
        ];
    }

    private static function nextNumber(int $year): int
    {
        $max = Quote::query()->where('year', $year)->max('number');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }
}
