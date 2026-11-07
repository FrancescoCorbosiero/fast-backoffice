<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Contributions;

use App\Modules\Pipeline\Data\WebsitePipelineData;
use App\Modules\Pipeline\Enums\PipelineStatus;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages\CreateChangeRequest;
use App\Modules\Pipeline\Filament\Resources\WebsitePipelines\PipelineActions;
use App\Modules\Pipeline\Filament\Resources\WebsitePipelines\WebsitePipelineResource;
use App\Modules\Pipeline\Filament\Widgets\WebsitePipelineTable;
use App\Modules\Pipeline\Queries\WebsitePipelines;
use App\Modules\Websites\Contracts\WebsitePanel;
use App\Modules\Websites\Queries\Websites;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Icons\Heroicon;

/**
 * The website page's "Pipeline" section: whether the site takes change
 * requests, from which repository, the requests so far, and the next
 * step: link a repository, read the onboarding, or ask for a change.
 */
final class PipelineWebsitePanel implements WebsitePanel
{
    public function key(): string
    {
        return 'pipeline';
    }

    public function label(): string
    {
        return 'Pipeline';
    }

    public function sort(): int
    {
        return 30;
    }

    public function component(int $websiteId): Component
    {
        return Livewire::make(WebsitePipelineTable::class, ['websiteId' => $websiteId]);
    }

    public function createAction(int $websiteId): ?Action
    {
        $pipeline = app(WebsitePipelines::class)->forWebsite($websiteId);

        if ($pipeline === null || ! $pipeline->isReady()) {
            return $pipeline === null ? PipelineActions::link($websiteId, 'link-repository') : null;
        }

        return self::newRequest($websiteId);
    }

    /**
     * What the owner can do next for this site, by the pipeline's state.
     * Built fresh on every call: an Action instance belongs to one
     * Livewire component.
     *
     * @return list<Action>
     */
    public static function actions(int $websiteId, ?WebsitePipelineData $pipeline): array
    {
        if ($pipeline === null) {
            return [PipelineActions::link($websiteId, 'link-repository')];
        }

        return match ($pipeline->status) {
            PipelineStatus::Onboarding => [
                Action::make('onboarding')->label(WebsitePipelineResource::onboardingLabel($websiteId))->icon(Heroicon::OutlinedDocumentMagnifyingGlass)->color('warning')
                    ->url(WebsitePipelineResource::onboardingUrl($websiteId)),
            ],
            PipelineStatus::Ready => [self::newRequest($websiteId)],
            PipelineStatus::None => [PipelineActions::link($websiteId, 'link-repository')],
        };
    }

    /**
     * A request for this site, on the client's behalf: the form already
     * knows the client and the site, only the request is left to write.
     */
    public static function newRequest(int $websiteId): Action
    {
        $website = app(Websites::class)->find($websiteId);

        return Action::make('new-request')
            ->label('Nuova richiesta')
            ->icon(Heroicon::OutlinedSparkles)
            ->url(CreateChangeRequest::prefilledUrl(['contact_id' => $website?->contactId, 'website_id' => $websiteId]));
    }
}
