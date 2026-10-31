<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\PecMessages;

use App\Modules\Invoicing\Enums\PecMessageKind;
use App\Modules\Invoicing\Enums\PecMessageOutcome;
use App\Modules\Invoicing\Filament\Resources\PecMessages\Pages\ListPecMessages;
use App\Modules\Invoicing\Filament\Resources\PecMessages\Pages\ViewPecMessage;
use App\Modules\Invoicing\Models\PecMessage;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every PEC message the app read (sdi.md §5), with what it did about it.
 */
class PecMessageResource extends Resource
{
    protected static ?string $model = PecMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'messaggio PEC';

    protected static ?string $pluralModelLabel = 'Casella PEC';

    protected static ?string $slug = 'pec-messages';

    public static function table(Table $table): Table
    {
        $kinds = [];
        foreach (PecMessageKind::cases() as $kind) {
            $kinds[$kind->value] = $kind->label();
        }
        $outcomes = [];
        foreach (PecMessageOutcome::cases() as $outcome) {
            $outcomes[$outcome->value] = $outcome->label();
        }

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('received_at')->label('Ricevuto')->dateTime('d/m/Y H:i')->placeholder('—')->sortable(),
                TextColumn::make('kind')->label('Tipo')->formatStateUsing(fn (PecMessageKind $state): string => $state->label()),
                TextColumn::make('file_name')->label('File')->placeholder('—')->searchable(),
                TextColumn::make('subject')->label('Oggetto')->limit(50)->placeholder('—')->searchable(),
                TextColumn::make('outcome')->label('Esito')->badge()->formatStateUsing(fn (PecMessageOutcome $state): string => $state->label())->color(fn (PecMessageOutcome $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('outcome')->label('Esito')->options($outcomes),
                SelectFilter::make('kind')->label('Tipo')->options($kinds),
            ])
            ->recordActions([ViewAction::make()])
            ->paginated([25, 50]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = PecMessage::query()->whereIn('outcome', [PecMessageOutcome::Review->value, PecMessageOutcome::Failed->value])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPecMessages::route('/'),
            'view' => ViewPecMessage::route('/{record}'),
        ];
    }
}
