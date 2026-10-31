<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Data\ServiceData;
use App\Modules\Catalog\Models\Service;

final readonly class Services
{
    /**
     * @return list<ServiceData>
     */
    public function active(): array
    {
        return array_values(Service::query()->active()->orderBy('sort')->orderBy('name')->get()
            ->map(fn (Service $service): ServiceData => $service->toData())->all());
    }

    public function find(int $id): ?ServiceData
    {
        return Service::query()->find($id)?->toData();
    }

    /**
     * @return array<int, string> id → name, for selects
     */
    public function options(): array
    {
        $options = [];
        foreach ($this->active() as $service) {
            $options[$service->id] = $service->name.' · '.$service->unitPrice->format();
        }

        return $options;
    }
}
