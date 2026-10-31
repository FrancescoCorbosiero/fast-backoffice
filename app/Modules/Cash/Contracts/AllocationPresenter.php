<?php

declare(strict_types=1);

namespace App\Modules\Cash\Contracts;

use App\Kernel\Reference;

/**
 * Lets the module that allocates movements say how its documents are
 * shown on the Cash screens (a label and a link), without Cash knowing
 * the module. Register with the "cash.allocation-presenters" tag.
 */
interface AllocationPresenter
{
    public const string TAG = 'cash.allocation-presenters';

    public function supports(Reference $allocation): bool;

    public function label(Reference $allocation): string;

    public function url(Reference $allocation): ?string;
}
