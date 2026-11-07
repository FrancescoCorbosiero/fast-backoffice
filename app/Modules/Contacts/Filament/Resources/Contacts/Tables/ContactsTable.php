<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Resources\Contacts\Tables;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Models\Contact;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('display_name')
            ->columns([
                TextColumn::make('display_name')->label('Nome')->searchable()->sortable()->weight('bold'),
                TextColumn::make('roles')->label('Ruoli')->badge()
                    ->state(fn (Contact $record): array => $record->roleEnums())
                    ->formatStateUsing(fn (ContactRole $state): string => $state->label())
                    ->color(fn (ContactRole $state): string => $state->color()),
                TextColumn::make('email')->label('Email')->searchable()->placeholder('—')
                    ->icon(fn (Contact $record): ?string => $record->email_suppressed_at !== null ? 'heroicon-o-no-symbol' : null)
                    ->iconColor('danger'),
                TextColumn::make('vat_number')->label('Partita IVA')->searchable()->placeholder('—'),
                TextColumn::make('tax_code')->label('Codice fiscale')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('city')->label('Comune')->sortable()->placeholder('—'),
                TextColumn::make('country')->label('Nazione')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('mailable')->label('Raggiungibili via email')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('email')->where('email_consent', true)->whereNull('email_suppressed_at')),
                Filter::make('invoiceable')->label('Con identità fiscale')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $query) => $query->whereNotNull('vat_number')->orWhereNotNull('tax_code'))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('Nessun contatto')
            ->emptyStateDescription('Clienti, fornitori e collaboratori dello studio. Da un contatto nascono preventivi, fatture e siti.')
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateActions([
                CreateAction::make()->label('Nuovo contatto'),
            ])
            ->paginated([25, 50, 100]);
    }
}
