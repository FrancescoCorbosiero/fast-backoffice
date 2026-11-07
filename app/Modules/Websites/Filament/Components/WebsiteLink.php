<?php

declare(strict_types=1);

namespace App\Modules\Websites\Filament\Components;

use App\Modules\Websites\Filament\Resources\Websites\WebsiteResource;

/**
 * Where a website's page is, for the modules that link to websites
 * (domains, repositories, the pipeline).
 */
final class WebsiteLink
{
    public static function url(int $websiteId): string
    {
        return WebsiteResource::getUrl('view', ['record' => $websiteId]);
    }
}
