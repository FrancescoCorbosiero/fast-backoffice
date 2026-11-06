<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Models;

use App\Modules\Pipeline\Data\WebsitePipelineData;
use App\Modules\Pipeline\Database\Factories\WebsitePipelineFactory;
use App\Modules\Pipeline\Enums\PipelineKind;
use App\Modules\Pipeline\Enums\PipelineStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $website_id
 * @property int $repository_id
 * @property PipelineKind $kind
 * @property PipelineStatus $status
 */
class WebsitePipeline extends Model
{
    /** @use HasFactory<WebsitePipelineFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PipelineKind::class,
            'status' => PipelineStatus::class,
        ];
    }

    protected static function newFactory(): WebsitePipelineFactory
    {
        return WebsitePipelineFactory::new();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', PipelineStatus::Ready->value);
    }

    public function toData(): WebsitePipelineData
    {
        return new WebsitePipelineData(
            id: $this->id,
            websiteId: $this->website_id,
            repositoryId: $this->repository_id,
            kind: $this->kind,
            status: $this->status,
        );
    }
}
