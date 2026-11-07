<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Contributions;

use App\Modules\Repositories\Filament\Widgets\WebsiteRepositoriesTable;
use App\Modules\Websites\Contracts\WebsitePanel;
use App\Modules\Websites\Queries\Websites;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;

final class RepositoriesWebsitePanel implements WebsitePanel
{
    public function key(): string
    {
        return 'repositories';
    }

    public function label(): string
    {
        return 'Repository';
    }

    public function sort(): int
    {
        return 20;
    }

    public function component(int $websiteId): Component
    {
        return Livewire::make(WebsiteRepositoriesTable::class, ['websiteId' => $websiteId]);
    }

    public function createAction(int $websiteId): Action
    {
        return self::newRepository($websiteId);
    }

    /**
     * The one way to add a repository for a site: the modal form, already
     * knowing the site and its client. Built fresh on every call: an
     * Action instance belongs to one Livewire component.
     */
    public static function newRepository(int $websiteId): Action
    {
        $website = app(Websites::class)->find($websiteId);

        return RepositoriesContactPanel::newRepository($website?->contactId, $websiteId);
    }
}
