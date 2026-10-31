<?php

declare(strict_types=1);

namespace App\Modules\Settings\Queries;

use App\Modules\Settings\Data\SdiSettings;
use App\Modules\Settings\Models\Setting;

final readonly class GetSdiSettings
{
    public const string KEY = 'sdi';

    public function get(): SdiSettings
    {
        $stored = Setting::read(self::KEY);

        return $stored === null ? new SdiSettings : SdiSettings::fromArray($stored);
    }
}
