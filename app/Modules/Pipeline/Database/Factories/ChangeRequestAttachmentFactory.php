<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Database\Factories;

use App\Modules\Pipeline\Enums\ImageFormat;
use App\Modules\Pipeline\Models\ChangeRequestAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A row only: a test that needs the file writes it to the disk itself.
 *
 * @extends Factory<ChangeRequestAttachment>
 */
class ChangeRequestAttachmentFactory extends Factory
{
    protected $model = ChangeRequestAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'change_request_id' => null, // the test supplies the request
            'original_name' => 'foto.jpg',
            'stored_path' => mb_strtolower((string) Str::ulid()).'.jpg',
            'mime' => ImageFormat::Jpeg,
            'size_bytes' => 1024,
            'sha256' => str_repeat('0', 64),
            'width' => 640,
            'height' => 480,
            'position' => 1,
        ];
    }
}
