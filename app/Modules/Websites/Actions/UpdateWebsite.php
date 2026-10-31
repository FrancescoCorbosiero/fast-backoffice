<?php

declare(strict_types=1);

namespace App\Modules\Websites\Actions;

use App\Modules\Websites\Data\WebsiteData;
use App\Modules\Websites\Data\WebsiteInput;
use App\Modules\Websites\Models\Website;
use Illuminate\Validation\ValidationException;

final readonly class UpdateWebsite
{
    public function __construct(private CreateWebsite $create) {}

    /**
     * @throws ValidationException
     */
    public function update(int $websiteId, WebsiteInput $input): WebsiteData
    {
        $this->create->validate($input, $websiteId);
        $website = Website::query()->findOrFail($websiteId);
        $attributes = CreateWebsite::attributes($input);

        // The anchor of the hosting cadence survives edits that don't move the renewal date.
        if ($input->hostingStartedOn === null && $website->hosting_started_on !== null && $website->hosting_renews_on?->toDateString() === $input->hostingRenewsOn?->toDateString()) {
            $attributes['hosting_started_on'] = $website->hosting_started_on->toDateString();
        }

        $website->fill($attributes)->save();

        return $website->toData();
    }
}
