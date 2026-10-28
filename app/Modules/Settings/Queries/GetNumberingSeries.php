<?php

declare(strict_types=1);

namespace App\Modules\Settings\Queries;

use App\Modules\Settings\Data\NumberingSeries;
use App\Modules\Settings\Models\Setting;

final readonly class GetNumberingSeries
{
    public const string KEY = 'numbering_series';

    public function get(): NumberingSeries
    {
        $stored = Setting::read(self::KEY);

        return $stored === null ? NumberingSeries::default() : NumberingSeries::fromArray($stored);
    }
}
