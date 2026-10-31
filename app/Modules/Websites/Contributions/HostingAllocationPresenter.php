<?php

declare(strict_types=1);

namespace App\Modules\Websites\Contributions;

use App\Kernel\Reference;
use App\Modules\Cash\Contracts\AllocationPresenter;
use App\Modules\Websites\Filament\Resources\Websites\WebsiteResource;
use App\Modules\Websites\Models\Website;

/**
 * hosting_renewal:{website}:{date} on the Cash screens.
 */
final class HostingAllocationPresenter implements AllocationPresenter
{
    public function supports(Reference $allocation): bool
    {
        return $allocation->type === 'hosting_renewal';
    }

    public function label(Reference $allocation): string
    {
        [$id, $date] = self::parts($allocation);
        $website = Website::query()->find($id);

        return 'Hosting '.($website !== null ? $website->name : '#'.$id).($date !== null ? ' · '.$date : '');
    }

    public function url(Reference $allocation): ?string
    {
        [$id] = self::parts($allocation);

        return Website::query()->whereKey($id)->exists() ? WebsiteResource::getUrl('view', ['record' => $id]) : null;
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
