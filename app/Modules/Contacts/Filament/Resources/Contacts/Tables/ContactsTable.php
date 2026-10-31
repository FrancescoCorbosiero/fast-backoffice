<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Filament\Resources\Contacts\Tables;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Models\Contact;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
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
                    ->state(fn (Contact $record): array => array_map(fn (ContactRole $role): string => $role->label(), $record->roleEnums())),
                TextColumn::make('email')->label('Email')->searchable()->placeholder('—')
                    ->icon(fn (Contact $record): ?string => $record->email_suppressed_at !== null ? 'heroicon-o-no-symbol' : null)
                    ->iconColor('danger'),
                TextColumn::make('vat_number')->label('Partita IVA')->searchable()->placeholder('—'),
                TextColumn::make('tax_code')->label('Codice fiscale')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('city')->label('Comune')->sortable()->placeholder('—'),
                TextColumn::make('country')->label('Nazione')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')->label('Ruolo')->options(ContactRole::options())
                    ->query(function (Builder $query, array $data): Builder {
                        $role = is_string($data['value'] ?? null) ? ContactRole::tryFrom($data['value']) : null;

                        return $role === null ? $query : $query->whereJsonContains('roles', $role->value);
                    }),
                Filter::make('mailable')->label('Raggiungibili via email')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('email')->where('email_consent', true)->whereNull('email_suppressed_at')),
                Filter::make('invoiceable')->label('Con identità fiscale')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $query) => $query->whereNotNull('vat_number')->orWhereNotNull('tax_code'))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->paginated([25, 50, 100]);
    }
}
