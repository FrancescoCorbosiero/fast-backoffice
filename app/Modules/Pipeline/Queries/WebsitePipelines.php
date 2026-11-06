<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Queries;

use App\Modules\Pipeline\Data\WebsitePipelineData;
use App\Modules\Pipeline\Models\WebsitePipeline;
use App\Modules\Websites\Data\WebsiteData;
use App\Modules\Websites\Queries\Websites;

final readonly class WebsitePipelines
{
    public function __construct(private Websites $websites) {}

    public function find(int $id): ?WebsitePipelineData
    {
        return WebsitePipeline::query()->find($id)?->toData();
    }

    public function forWebsite(int $websiteId): ?WebsitePipelineData
    {
        return WebsitePipeline::query()->where('website_id', $websiteId)->first()?->toData();
    }

    /**
     * @param  list<int>  $websiteIds
     * @return array<int, WebsitePipelineData> keyed by website id
     */
    public function forWebsites(array $websiteIds): array
    {
        if ($websiteIds === []) {
            return [];
        }

        $pipelines = [];
        foreach (WebsitePipeline::query()->whereIn('website_id', $websiteIds)->get() as $pipeline) {
            $pipelines[$pipeline->website_id] = $pipeline->toData();
        }

        return $pipelines;
    }

    /**
     * The contact's websites that take change requests.
     *
     * @return list<WebsiteData>
     */
    public function readyForContact(int $contactId): array
    {
        $websites = $this->websites->forContact($contactId);
        $pipelines = $this->forWebsites(array_map(fn (WebsiteData $w): int => $w->id, $websites));

        return array_values(array_filter($websites, fn (WebsiteData $w): bool => isset($pipelines[$w->id]) && $pipelines[$w->id]->isReady()));
    }

    /**
     * @return array<int, string> website id → name, for a select of ready sites
     */
    public function readyOptions(): array
    {
        $ready = WebsitePipeline::query()->ready()->pluck('website_id')->all();
        $options = [];
        foreach ($this->websites->active() as $website) {
            if (in_array($website->id, $ready, true)) {
                $options[$website->id] = $website->name.' · '.$website->host;
            }
        }

        return $options;
    }
}
