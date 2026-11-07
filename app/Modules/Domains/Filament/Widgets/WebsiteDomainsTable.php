<?php

declare(strict_types=1);

namespace App\Modules\Domains\Filament\Widgets;

use App\Modules\Domains\Contributions\DomainsWebsitePanel;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Filament\Resources\Domains\DomainResource;
use App\Modules\Domains\Models\Domain;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class WebsiteDomainsTable extends TableWidget
{
    public int $websiteId = 0;

    protected static bool $isDiscovered = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Domain::query()->where('website_id', $this->websiteId))
            ->heading('Domini')
            ->headerActions([DomainsWebsitePanel::newDomain($this->websiteId)])
            ->defaultSort('expires_on')
            ->columns([
                TextColumn::make('name')->label('Dominio')->url(fn (Domain $record): string => DomainResource::getUrl('view', ['record' => $record])),
                TextColumn::make('expires_on')->label('Scade il')->date('d/m/Y'),
                IconColumn::make('auto_renew')->label('Auto')->boolean(),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (DomainStatus $state): string => $state->label())->color(fn (DomainStatus $state): string => $state->color()),
            ])
            ->emptyStateIcon(Heroicon::OutlinedGlobeEuropeAfrica)
            ->emptyStateHeading('Nessun dominio')
            ->emptyStateDescription('La scadenza entra nella previsione di cassa e "Registra rinnovo" la sposta avanti.')
            ->emptyStateActions([DomainsWebsitePanel::newDomain($this->websiteId)])
            ->paginated([10, 25]);
    }
}
