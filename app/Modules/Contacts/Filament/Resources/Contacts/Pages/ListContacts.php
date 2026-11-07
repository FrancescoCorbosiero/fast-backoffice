<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Resources\Contacts\Pages;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Resources\Contacts\ContactResource;
use App\Modules\Contacts\Models\Contact;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * "My clients" and "my suppliers" are two lists in the owner's head, so
 * they are two tabs here: everyone, then one tab per role with its count.
 */
class ListContacts extends ListRecords
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('Tutti')];

        foreach (ContactRole::cases() as $role) {
            $tabs[$role->value] = Tab::make(self::plural($role))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereJsonContains('roles', $role->value))
                ->badge(fn (): int => Contact::query()->live()->withRole($role)->count())
                ->badgeColor($role->color());
        }

        return $tabs;
    }

    private static function plural(ContactRole $role): string
    {
        return match ($role) {
            ContactRole::Client => 'Clienti',
            ContactRole::Supplier => 'Fornitori',
            ContactRole::Partner => 'Collaboratori',
            ContactRole::Other => 'Altri',
        };
    }
}
