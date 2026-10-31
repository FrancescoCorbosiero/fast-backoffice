<?php

declare(strict_types=1);

namespace App\Modules\Domains\Contributions;

use App\Kernel\Reference;
use App\Modules\Cash\Contracts\AllocationPresenter;
use App\Modules\Domains\Filament\Resources\Domains\DomainResource;
use App\Modules\Domains\Models\Domain;

/**
 * domain_renewal:{domain}:{date} on the Cash screens.
 */
final class DomainAllocationPresenter implements AllocationPresenter
{
    public function supports(Reference $allocation): bool
    {
        return $allocation->type === 'domain_renewal';
    }

    public function label(Reference $allocation): string
    {
        [$id, $date] = self::parts($allocation);
        $domain = Domain::query()->find($id);

        return 'Dominio '.($domain !== null ? $domain->name : '#'.$id).($date !== null ? ' · '.$date : '');
    }

    public function url(Reference $allocation): ?string
    {
        [$id] = self::parts($allocation);

        return Domain::query()->whereKey($id)->exists() ? DomainResource::getUrl('view', ['record' => $id]) : null;
    }

    /**
     * @return array{int, ?string}
     */
    private static function parts(Reference $allocation): array
    {
        $segments = explode(':', (string) $allocation->key, 2);

        return [(int) $segments[0], $segments[1] ?? null];
    }
}
