<?php

declare(strict_types=1);

namespace App\Modules\Websites\Database\Factories;

use App\Modules\Websites\Enums\WebsiteStatus;
use App\Modules\Websites\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Website>
 */
class WebsiteFactory extends Factory
{
    protected $model = Website::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => null, // the test supplies a contact from the Contacts module
            'name' => 'Pasticceria Bellavista',
            'url' => 'https://www.bellavista.example',
            'host' => 'www.bellavista.example',
            'status' => WebsiteStatus::Active,
            'uptime_enabled' => true,
        ];
    }

    public function hosted(string $renewsOn = '2026-04-15', int $costCents = 12_000, string $frequency = 'yearly'): static
    {
        return $this->state(['hosting_provider' => 'Hetzner', 'hosting_started_on' => $renewsOn, 'hosting_renews_on' => $renewsOn, 'hosting_frequency' => $frequency, 'hosting_cost_cents' => $costCents]);
    }
}
