<?php

declare(strict_types=1);

namespace App\Modules\Websites\Queries;

use App\Modules\Websites\Data\WebsiteData;
use App\Modules\Websites\Models\Website;

final readonly class Websites
{
    public function find(int $id): ?WebsiteData
    {
        return Website::query()->find($id)?->toData();
    }

    /**
     * By host, through the indexed column (hive-crm loaded every website).
     */
    public function byHost(string $host): ?WebsiteData
    {
        return Website::query()->where('host', mb_strtolower(trim($host)))->orderBy('id')->first()?->toData();
    }

    /**
     * @return list<WebsiteData>
     */
    public function active(): array
    {
        return $this->map(Website::query()->active()->orderBy('name')->get()->all());
    }

    /**
     * Active sites with uptime checks on.
     *
     * @return list<WebsiteData>
     */
    public function monitored(): array
    {
        return $this->map(Website::query()->monitored()->orderBy('id')->get()->all());
    }

    /**
     * @return list<WebsiteData>
     */
    public function down(): array
    {
        return $this->map(Website::query()->monitored()->whereNotNull('down_since')->orderBy('down_since')->get()->all());
    }

    /**
     * @return list<WebsiteData>
     */
    public function forContact(int $contactId): array
    {
        return $this->map(Website::query()->where('contact_id', $contactId)->orderBy('name')->get()->all());
    }

    /**
     * @return array<int, string> id → name, for a select
     */
    public function options(): array
    {
        $options = [];
        foreach ($this->active() as $website) {
            $options[$website->id] = $website->name.' · '.$website->host;
        }

        return $options;
    }

    /**
     * @param  array<int, Website>  $websites
     * @return list<WebsiteData>
     */
    private function map(array $websites): array
    {
        return array_values(array_map(fn (Website $w): WebsiteData => $w->toData(), $websites));
    }
}
