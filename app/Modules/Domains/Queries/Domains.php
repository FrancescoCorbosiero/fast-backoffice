<?php

declare(strict_types=1);

namespace App\Modules\Domains\Queries;

use App\Modules\Domains\Data\DomainData;
use App\Modules\Domains\Models\Domain;
use Carbon\CarbonImmutable;

final readonly class Domains
{
    public function find(int $id): ?DomainData
    {
        return Domain::query()->find($id)?->toData();
    }

    /**
     * @return list<DomainData> soonest to expire first
     */
    public function active(): array
    {
        return $this->map(Domain::query()->active()->orderBy('expires_on')->orderBy('name')->get()->all());
    }

    /**
     * Active domains expiring within the window, the already-expired included.
     *
     * @return list<DomainData>
     */
    public function expiringWithin(CarbonImmutable $today, int $days): array
    {
        return $this->map(Domain::query()->active()->where('expires_on', '<=', $today->startOfDay()->addDays($days)->toDateString())->orderBy('expires_on')->orderBy('name')->get()->all());
    }

    /**
     * @return list<DomainData>
     */
    public function forContact(int $contactId): array
    {
        return $this->map(Domain::query()->where('contact_id', $contactId)->orderBy('expires_on')->get()->all());
    }

    /**
     * @return list<DomainData>
     */
    public function forWebsite(int $websiteId): array
    {
        return $this->map(Domain::query()->where('website_id', $websiteId)->orderBy('name')->get()->all());
    }

    /**
     * @param  array<int, Domain>  $domains
     * @return list<DomainData>
     */
    private function map(array $domains): array
    {
        return array_values(array_map(fn (Domain $d): DomainData => $d->toData(), $domains));
    }
}
