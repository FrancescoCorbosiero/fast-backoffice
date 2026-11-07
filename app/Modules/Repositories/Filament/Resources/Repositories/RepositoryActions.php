<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Filament\Resources\Repositories;

use App\Modules\Repositories\Actions\SaveRepository;
use App\Modules\Repositories\Data\RepositoryInput;
use App\Modules\Repositories\Models\Repository;
use App\Support\Filament\FormErrors;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * The modal actions that create and edit a repository, wherever they
 * appear (the list, a contact's page, a website's page): one form, one
 * use case, and the use case's errors shown on the form's fields.
 */
final class RepositoryActions
{
    /**
     * @param  array<string, int|null>  $prefill  fields already known where the action sits (the contact, the website)
     */
    public static function create(array $prefill = [], string $name = 'create'): Action
    {
        return Action::make($name)
            ->label('Nuovo repository')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Nuovo repository')
            ->modalSubmitActionLabel('Crea')
            ->schema(fn (Schema $schema): Schema => RepositoryResource::form($schema))
            ->fillForm(array_filter($prefill, fn (?int $value): bool => $value !== null && $value > 0))
            ->action(function (array $data, Schema $schema): void {
                try {
                    app(SaveRepository::class)->create(RepositoryInput::fromArray($data));
                } catch (ValidationException $exception) {
                    FormErrors::onSchema($schema, $exception);
                }

                Notification::make()->title('Repository creato')->success()->send();
            });
    }

    public static function edit(): Action
    {
        return Action::make('edit')
            ->label('Modifica')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading('Modifica repository')
            ->modalSubmitActionLabel('Salva')
            ->schema(fn (Schema $schema): Schema => RepositoryResource::form($schema))
            ->fillForm(fn (Repository $record): array => $record->only(['name', 'url', 'provider', 'default_branch', 'contact_id', 'website_id', 'notes']))
            ->action(function (Repository $record, array $data, Schema $schema): void {
                try {
                    app(SaveRepository::class)->update($record->id, RepositoryInput::fromArray($data));
                } catch (ValidationException $exception) {
                    FormErrors::onSchema($schema, $exception);
                }

                Notification::make()->title('Repository aggiornato')->success()->send();
            });
    }
}
