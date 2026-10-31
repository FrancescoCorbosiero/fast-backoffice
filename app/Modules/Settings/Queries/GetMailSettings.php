<?php

declare(strict_types=1);

namespace App\Modules\Settings\Queries;

use App\Modules\Settings\Data\MailSettings;
use App\Modules\Settings\Models\Setting;

final readonly class GetMailSettings
{
    public const string KEY = 'mail';

    public function get(): MailSettings
    {
        $stored = Setting::read(self::KEY);

        return $stored === null ? new MailSettings : MailSettings::fromArray($stored);
    }
}
