<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Models;

use App\Modules\Repositories\Data\RepositoryData;
use App\Modules\Repositories\Database\Factories\RepositoryFactory;
use App\Modules\Repositories\Enums\RepositoryProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $url
 * @property RepositoryProvider $provider
 * @property ?int $contact_id
 * @property ?int $website_id
 * @property string $default_branch
 * @property ?string $notes
 */
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['default_branch' => 'main'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['provider' => RepositoryProvider::class];
    }

    protected static function newFactory(): RepositoryFactory
    {
        return RepositoryFactory::new();
    }

    public function toData(): RepositoryData
    {
        return new RepositoryData($this->id, $this->name, $this->url, $this->provider, $this->contact_id, $this->website_id, $this->default_branch, $this->notes);
    }
}
