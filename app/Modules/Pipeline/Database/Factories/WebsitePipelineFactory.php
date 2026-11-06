<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Database\Factories;

use App\Modules\Pipeline\Enums\PipelineKind;
use App\Modules\Pipeline\Enums\PipelineStatus;
use App\Modules\Pipeline\Models\WebsitePipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebsitePipeline>
 */
class WebsitePipelineFactory extends Factory
{
    protected $model = WebsitePipeline::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'website_id' => null, // the test supplies a website and a repository
            'repository_id' => null,
            'kind' => PipelineKind::StaticSite,
            'status' => PipelineStatus::Ready,
        ];
    }

    public function onboarding(): static
    {
        return $this->state(['status' => PipelineStatus::Onboarding]);
    }
}
