<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Widgets;

use App\Modules\Pipeline\Contributions\PipelineWebsitePanel;
use App\Modules\Pipeline\Data\WebsitePipelineData;
use App\Modules\Pipeline\Enums\ChangeRequestKind;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\ChangeRequestResource;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Queries\WebsitePipelines;
use App\Modules\Repositories\Queries\Repositories;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The website page's Pipeline section: the pipeline's state and
 * repository in the heading, the site's requests below, and the next
 * step in the header and the empty state.
 */
class WebsitePipelineTable extends TableWidget
{
    public int $websiteId = 0;

    protected static bool $isDiscovered = false;

    public function table(Table $table): Table
    {
        $pipeline = app(WebsitePipelines::class)->forWebsite($this->websiteId);

        return $table
            ->query(fn (): Builder => ChangeRequest::query()->where('website_id', $this->websiteId))
            ->heading('Pipeline')
            ->description(self::describe($pipeline))
            ->headerActions(PipelineWebsitePanel::actions($this->websiteId, $pipeline))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->url(fn (ChangeRequest $record): string => ChangeRequestResource::getUrl('view', ['record' => $record])),
                TextColumn::make('request_text')->label('Richiesta')->limit(70)->url(fn (ChangeRequest $record): string => ChangeRequestResource::getUrl('view', ['record' => $record])),
                TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn (ChangeRequestKind $state): string => $state->label())->color(fn (ChangeRequestKind $state): string => $state->color()),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (ChangeRequestStatus $state): string => $state->label())->color(fn (ChangeRequestStatus $state): string => $state->color()),
                TextColumn::make('created_at')->label('Creata')->dateTime('d/m/Y H:i'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedRocketLaunch)
            ->emptyStateHeading($pipeline === null || ! $pipeline->isReady() ? 'Nessun repository collegato' : 'Nessuna richiesta')
            ->emptyStateDescription($pipeline === null || ! $pipeline->isReady() ? 'Collegane uno per attivare le richieste di modifica: l\'onboarding analizza il repository e tu approvi pipeline.yml e CLAUDE.md.' : 'Le richieste del cliente dal portale compaiono qui; puoi anche farne una per suo conto.')
            ->emptyStateActions(PipelineWebsitePanel::actions($this->websiteId, $pipeline))
            ->paginated([10, 25]);
    }

    /**
     * "Pronta · github.com/studio/sito (main)", or why there is nothing yet.
     */
    private static function describe(?WebsitePipelineData $pipeline): string
    {
        if ($pipeline === null) {
            return 'Non configurata: il sito non riceve ancora richieste di modifica.';
        }

        $repository = app(Repositories::class)->find($pipeline->repositoryId);

        return $pipeline->status->label().' · '.($repository === null ? 'repository #'.$pipeline->repositoryId : $repository->url.' ('.$repository->defaultBranch.')');
    }
}
