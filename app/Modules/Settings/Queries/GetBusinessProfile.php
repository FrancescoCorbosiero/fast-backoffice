<?php

declare(strict_types=1);

namespace App\Modules\Settings\Queries;

use App\Modules\Settings\Data\BusinessProfile;
use App\Modules\Settings\Models\Setting;

final readonly class GetBusinessProfile
{
    public const string KEY = 'business_profile';

    public function get(): BusinessProfile
    {
        return BusinessProfile::fromArray(Setting::read(self::KEY) ?? []);
    }
}
