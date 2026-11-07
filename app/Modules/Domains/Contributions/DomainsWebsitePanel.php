<?php

declare(strict_types=1);

namespace App\Modules\Domains\Contributions;

use App\Modules\Domains\Filament\Widgets\WebsiteDomainsTable;
use App\Modules\Websites\Contracts\WebsitePanel;
use App\Modules\Websites\Queries\Websites;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;

final class DomainsWebsitePanel implements WebsitePanel
{
    public function key(): string
    {
        return 'domains';
    }

    public function label(): string
    {
        return 'Domini';
    }

    public function sort(): int
    {
        return 10;
    }

    public function component(int $websiteId): Component
    {
        return Livewire::make(WebsiteDomainsTable::class, ['websiteId' => $websiteId]);
    }

    public function createAction(int $websiteId): Action
    {
        return self::newDomain($websiteId);
    }

    /**
     * The one way to add a domain for a site: the create form, already
     * knowing the site and its client. Built fresh on every call: an
     * Action instance belongs to one Livewire component.
     */
    public static function newDomain(int $websiteId): Action
    {
        $website = app(Websites::class)->find($websiteId);

        return DomainsContactPanel::newDomain($website !== null ? $website->contactId : 0, $websiteId);
    }
}
