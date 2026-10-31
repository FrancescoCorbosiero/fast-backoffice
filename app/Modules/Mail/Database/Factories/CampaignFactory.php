<?php

declare(strict_types=1);

namespace App\Modules\Mail\Database\Factories;

use App\Modules\Mail\Enums\CampaignStatus;
use App\Modules\Mail\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Novità di primavera',
            'subject' => 'Un sito nuovo per la primavera',
            'preheader' => 'Tre idee per il tuo sito',
            'body_html' => '<p>Ciao, ecco tre idee per rinnovare il tuo sito.</p>',
            'audience_roles' => [],
            'status' => CampaignStatus::Draft,
        ];
    }
}
