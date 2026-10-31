<?php

declare(strict_types=1);

namespace App\Modules\Cash\Queries;

use App\Modules\Cash\Data\CategoryData;
use App\Modules\Cash\Enums\CategoryKind;
use App\Modules\Cash\Models\Category;

final readonly class Categories
{
    /**
     * @return list<CategoryData>
     */
    public function active(CategoryKind $kind): array
    {
        return array_values(Category::query()->active()->where('kind', $kind->value)->orderBy('sort')->orderBy('name')->get()
            ->map(fn (Category $c): CategoryData => $c->toData())->all());
    }

    /**
     * @return array<int, string> id → name, for a select
     */
    public function options(CategoryKind $kind): array
    {
        $options = [];
        foreach ($this->active($kind) as $category) {
            $options[$category->id] = $category->name;
        }

        return $options;
    }

    public function find(int $id): ?CategoryData
    {
        return Category::query()->find($id)?->toData();
    }
}
