<?php

declare(strict_types=1);

namespace App\Modules\Domains\Filament\Widgets;

use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Filament\Resources\Domains\DomainResource;
use App\Modules\Domains\Models\Domain;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ContactDomainsTable extends TableWidget
{
    public int $contactId = 0;

    protected static bool $isDiscovered = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Domain::query()->where('contact_id', $this->contactId))
            ->defaultSort('expires_on')
            ->columns([
                TextColumn::make('name')->label('Dominio')->url(fn (Domain $record): string => DomainResource::getUrl('view', ['record' => $record])),
                TextColumn::make('expires_on')->label('Scade il')->date('d/m/Y'),
                IconColumn::make('auto_renew')->label('Auto')->boolean(),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (DomainStatus $state): string => $state->label())->color(fn (DomainStatus $state): string => $state->color()),
            ])
            ->emptyStateHeading('Nessun dominio')
            ->paginated([10, 25]);
    }
}
