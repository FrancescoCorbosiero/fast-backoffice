<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Resources\WebsitePipelines;

use App\Modules\Pipeline\Actions\LinkRepository;
use App\Modules\Pipeline\Enums\PipelineKind;
use App\Modules\Repositories\Queries\Repositories;
use App\Support\Filament\FormErrors;
use App\Support\Shape;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The modal that links a repository to a website and starts its
 * onboarding, wherever it appears (Pipeline siti, its empty state, the
 * website's page): one form, one use case, the use case's errors on the
 * form's fields. Built fresh on every call: an Action instance belongs
 * to one Livewire component.
 */
final class PipelineActions
{
    public const string REONBOARD_DESCRIPTION = 'Il repository viene analizzato di nuovo: pipeline.yml e CLAUDE.md verranno riscritti e attenderanno la tua approvazione.';

    /**
     * @param  ?int  $websiteId  the website already known where the action sits; its only repository is preselected
     */
    public static function link(?int $websiteId = null, string $name = 'link'): Action
    {
        return Action::make($name)
            ->label('Collega repository')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->modalHeading('Collega un repository')
            ->modalSubmitActionLabel('Collega')
            ->schema(fn (Schema $schema): Schema => WebsitePipelineResource::form($schema))
            ->fillForm(function () use ($websiteId): array {
                $repositories = $websiteId !== null && $websiteId > 0 ? app(Repositories::class)->forWebsite($websiteId) : [];

                return [
                    'website_id' => $websiteId !== null && $websiteId > 0 ? $websiteId : null,
                    'repository_id' => count($repositories) === 1 ? $repositories[0]->id : null,
                    'kind' => PipelineKind::StaticSite->value,
                ];
            })
            ->action(function (array $data, Schema $schema): void {
                $kind = PipelineKind::tryFrom(Shape::string($data, 'kind', PipelineKind::StaticSite->value)) ?? PipelineKind::StaticSite;

                try {
                    app(LinkRepository::class)->link(Shape::int($data, 'website_id'), Shape::int($data, 'repository_id'), $kind);
                } catch (ValidationException $exception) {
                    FormErrors::onSchema($schema, $exception);
                } catch (LogicException) {
                    Notification::make()->title('Operazione non riuscita')->body('Il sito ha una richiesta ancora aperta: chiudila prima di collegare un repository.')->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Onboarding avviato')->body('Il repository viene analizzato: pipeline.yml e CLAUDE.md attenderanno la tua approvazione.')->success()->send();
            });
    }
}
