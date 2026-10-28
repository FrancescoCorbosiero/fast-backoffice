<?php

declare(strict_types=1);

namespace App\Modules\Cash\Models;

use App\Modules\Cash\Data\CategoryData;
use App\Modules\Cash\Database\Factories\CategoryFactory;
use App\Modules\Cash\Enums\CategoryKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property CategoryKind $kind
 * @property bool $is_active
 * @property int $sort
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $table = 'cash_categories';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CategoryKind::class,
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function toData(): CategoryData
    {
        return new CategoryData($this->id, $this->name, $this->kind, $this->is_active);
    }
}
