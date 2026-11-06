<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Database\Factories;

use App\Modules\Pipeline\Enums\ChangeRequestKind;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Models\ChangeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChangeRequest>
 */
class ChangeRequestFactory extends Factory
{
    protected $model = ChangeRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => null, // the test supplies a contact and a website
            'website_id' => null,
            'client_name' => 'Pasticceria Bellavista S.r.l.',
            'website_name' => 'Pasticceria Bellavista',
            'kind' => ChangeRequestKind::Change,
            'status' => ChangeRequestStatus::Received,
            'request_text' => 'Cambia gli orari di apertura: il sabato chiudiamo alle 13.',
            'branch' => 'req/pending',
            'preview_slug' => mb_strtolower((string) Str::ulid()),
        ];
    }

    public function status(ChangeRequestStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function onboarding(): static
    {
        return $this->state(['kind' => ChangeRequestKind::Onboarding, 'branch' => 'pipeline/onboarding', 'request_text' => 'Onboarding del repository']);
    }

    public function previewReady(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ChangeRequestStatus::PreviewReady,
            'preview_url' => 'https://'.(is_string($attributes['preview_slug'] ?? null) ? $attributes['preview_slug'] : 'anteprima').'.preview.example.test',
            'log' => "Modificati gli orari in content/orari.md\n",
            'touched_paths' => ['content/orari.md'],
        ]);
    }
}
