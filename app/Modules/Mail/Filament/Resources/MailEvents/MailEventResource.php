<?php

declare(strict_types=1);

namespace App\Modules\Mail\Filament\Resources\MailEvents;

use App\Modules\Mail\Filament\Resources\Campaigns\CampaignResource;
use App\Modules\Mail\Filament\Resources\MailEvents\Pages\ListMailEvents;
use App\Modules\Mail\Models\MailEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every SNS delivery about SES the app received, and what it did with it:
 * the way to tell from the panel that SES is talking to the app at all.
 * Read-only, under Sistema like the PEC log.
 */
class MailEventResource extends Resource
{
    protected static ?string $model = MailEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 35;

    protected static ?string $modelLabel = 'evento SES';

    protected static ?string $pluralModelLabel = 'Eventi SES';

    protected static ?string $slug = 'mail-events';

    public const array OUTCOMES = ['processed' => 'Applicato', 'ignored' => 'Ignorato', 'confirmed' => 'Iscrizione confermata', 'rejected' => 'Rifiutato', 'duplicate' => 'Duplicato'];

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('recipient.campaign'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('received_at')->label('Ricevuto')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('event_type')->label('Evento')->placeholder('—'),
                TextColumn::make('outcome')->label('Esito')->badge()
                    ->formatStateUsing(fn (string $state): string => self::OUTCOMES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'processed', 'confirmed' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('recipient.email')->label('Destinatario')->placeholder('—')->searchable()
                    ->description(fn (MailEvent $record): ?string => $record->recipient?->campaign?->name)
                    ->url(fn (MailEvent $record): ?string => $record->recipient === null ? null : CampaignResource::getUrl('view', ['record' => $record->recipient->campaign_id])),
                TextColumn::make('note')->label('Note')->limit(70)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('outcome')->label('Esito')->options(self::OUTCOMES),
            ])
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

    public static function getPages(): array
    {
        return [
            'index' => ListMailEvents::route('/'),
        ];
    }
}
