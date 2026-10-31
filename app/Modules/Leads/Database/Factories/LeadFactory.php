<?php

declare(strict_types=1);

namespace App\Modules\Leads\Database\Factories;

use App\Modules\Leads\Enums\LeadStatus;
use App\Modules\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Giulia Verdi',
            'company' => 'Fioreria Verdi',
            'email' => 'giulia@verdi.example',
            'phone' => '+39 333 0000000',
            'source' => 'referral',
            'status' => LeadStatus::New,
            'estimated_value_cents' => 150_000,
        ];
    }
}
