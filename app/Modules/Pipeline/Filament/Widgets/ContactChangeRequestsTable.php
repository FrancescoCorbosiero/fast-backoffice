<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Widgets;

use App\Modules\Pipeline\Contributions\ChangeRequestsContactPanel;
use App\Modules\Pipeline\Enums\ChangeRequestKind;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\ChangeRequestResource;
use App\Modules\Pipeline\Filament\Resources\WebsitePipelines\WebsitePipelineResource;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Queries\WebsitePipelines;
use App\Modules\Websites\Filament\Components\WebsiteLink;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The contact page's change requests: the client's requests on their
 * sites, newest first, and a request on their behalf when one of their
 * sites has the pipeline ready.
 */
class ContactChangeRequestsTable extends TableWidget
{
    public int $contactId = 0;

    protected static bool $isDiscovered = false;

    public function table(Table $table): Table
    {
        $ready = app(WebsitePipelines::class)->readyForContact($this->contactId) !== [];

        return $table
            ->query(fn (): Builder => ChangeRequest::query()->forContact($this->contactId))
            ->heading('Richieste di modifica')
            ->headerActions($ready ? [ChangeRequestsContactPanel::newRequest($this->contactId)] : [])
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->url(fn (ChangeRequest $record): string => ChangeRequestResource::getUrl('view', ['record' => $record])),
                TextColumn::make('request_text')->label('Richiesta')->limit(60)->url(fn (ChangeRequest $record): string => ChangeRequestResource::getUrl('view', ['record' => $record])),
                TextColumn::make('website_name')->label('Sito')->url(fn (ChangeRequest $record): string => WebsiteLink::url($record->website_id)),
                TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn (ChangeRequestKind $state): string => $state->label())->color(fn (ChangeRequestKind $state): string => $state->color()),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (ChangeRequestStatus $state): string => $state->label())->color(fn (ChangeRequestStatus $state): string => $state->color()),
                TextColumn::make('created_at')->label('Creata')->dateTime('d/m/Y H:i'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedSparkles)
            ->emptyStateHeading($ready ? 'Nessuna richiesta' : 'Nessun sito con la pipeline pronta')
            ->emptyStateDescription($ready ? 'Le richieste del cliente dal portale compaiono qui; puoi anche farne una per suo conto.' : 'Collega un repository a uno dei siti del cliente: l\'onboarding lo prepara a ricevere richieste.')
            ->emptyStateActions($ready
                ? [ChangeRequestsContactPanel::newRequest($this->contactId)]
                : [Action::make('pipelines')->label('Pipeline siti')->icon(Heroicon::OutlinedRocketLaunch)->url(WebsitePipelineResource::getUrl('index'))])
            ->paginated([10, 25]);
    }
}
