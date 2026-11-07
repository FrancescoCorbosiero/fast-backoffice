<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Contributions;

use App\Modules\Contacts\Contracts\ContactPanel;
use App\Modules\Repositories\Filament\Resources\Repositories\RepositoryActions;
use App\Modules\Repositories\Filament\Widgets\ContactRepositoriesTable;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Icons\Heroicon;

final class RepositoriesContactPanel implements ContactPanel
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
        return 40;
    }

    public function component(int $contactId): Component
    {
        return Livewire::make(ContactRepositoriesTable::class, ['contactId' => $contactId]);
    }

    public function createAction(int $contactId): Action
    {
        return self::newRepository($contactId);
    }

    /**
     * The one way to add a repository for a contact: the same modal form
     * the repositories page uses, already knowing the client (and the
     * site, when reached from one). Repositories have no create page, so
     * this is a modal, not a link; what SaveRepository refuses shows on
     * the form's own fields. Built fresh on every call: an Action
     * instance belongs to one Livewire component.
     */
    public static function newRepository(?int $contactId, ?int $websiteId = null): Action
    {
        return RepositoryActions::create(['contact_id' => $contactId, 'website_id' => $websiteId], 'new-repository')
            ->icon(Heroicon::OutlinedCodeBracket);
    }
}
