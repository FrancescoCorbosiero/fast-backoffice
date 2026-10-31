<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Queries;

use App\Modules\Repositories\Data\RepositoryData;
use App\Modules\Repositories\Models\Repository;

final readonly class Repositories
{
    public function find(int $id): ?RepositoryData
    {
        return Repository::query()->find($id)?->toData();
    }

    /**
     * @return list<RepositoryData>
     */
    public function forContact(int $contactId): array
    {
        return $this->map(Repository::query()->where('contact_id', $contactId)->orderBy('name')->get()->all());
    }

    /**
     * @return list<RepositoryData>
     */
    public function forWebsite(int $websiteId): array
    {
        return $this->map(Repository::query()->where('website_id', $websiteId)->orderBy('name')->get()->all());
    }

    /**
     * @param  array<int, Repository>  $repositories
     * @return list<RepositoryData>
     */
    private function map(array $repositories): array
    {
        return array_values(array_map(fn (Repository $r): RepositoryData => $r->toData(), $repositories));
    }
}
