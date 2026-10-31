<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament;

use App\Kernel\Reference;
use App\Modules\Cash\Contracts\AllocationPresenter;

/**
 * Labels and links for allocated movements, through the presenters the
 * allocating modules registered.
 */
final class Allocations
{
    public static function label(Reference $allocation): string
    {
        return self::presenter($allocation)?->label($allocation) ?? $allocation->toString();
    }

    public static function url(Reference $allocation): ?string
    {
        return self::presenter($allocation)?->url($allocation);
    }

    private static function presenter(Reference $allocation): ?AllocationPresenter
    {
        /** @var iterable<AllocationPresenter> $presenters */
        $presenters = app()->tagged(AllocationPresenter::TAG);

        foreach ($presenters as $presenter) {
            if ($presenter->supports($allocation)) {
                return $presenter;
            }
        }

        return null;
    }
}
