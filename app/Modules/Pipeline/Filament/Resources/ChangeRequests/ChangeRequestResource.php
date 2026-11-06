<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Filament\Resources\ChangeRequests;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Pipeline\Enums\ChangeRequestKind;
use App\Modules\Pipeline\Enums\ChangeRequestStatus;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages\CreateChangeRequest;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages\ListChangeRequests;
use App\Modules\Pipeline\Filament\Resources\ChangeRequests\Pages\ViewChangeRequest;
use App\Modules\Pipeline\Models\ChangeRequest;
use App\Modules\Pipeline\Queries\WebsitePipelines;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The clients' change requests and the onboarding runs: what was asked,
 * what the run did, the preview, and the owner's decision.
 */
class ChangeRequestResource extends Resource
{
    protected static ?string $model = ChangeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Asset';

    protected static ?int $navigationSort = 33;

    protected static ?string $modelLabel = 'richiesta di modifica';

    protected static ?string $pluralModelLabel = 'Richieste di modifica';

    protected static ?string $slug = 'change-requests';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->description('Una richiesta per conto del cliente: la pipeline la lavora come se arrivasse dal portale.')->components([
                ContactPicker::make('contact_id', [ContactRole::Client])->label('Cliente')->required(),
                Select::make('website_id')->label('Sito')->options(fn (): array => app(WebsitePipelines::class)->readyOptions())->searchable()->required()
                    ->helperText('Solo i siti con la pipeline pronta.'),
                Textarea::make('request_text')->label('Richiesta')->rows(6)->required()->maxLength(4000)->placeholder('Cosa va cambiato sul sito, con parole semplici.')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('client_name')->label('Cliente')->searchable()->weight('bold'),
                TextColumn::make('website_name')->label('Sito')->searchable(),
                TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn (ChangeRequestKind $state): string => $state->label())->color(fn (ChangeRequestKind $state): string => $state->color()),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (ChangeRequestStatus $state): string => $state->label())->color(fn (ChangeRequestStatus $state): string => $state->color()),
                TextColumn::make('created_at')->label('Creata')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(ChangeRequestStatus::options()),
                SelectFilter::make('kind')->label('Tipo')->options(ChangeRequestKind::options()),
            ])
            ->recordActions([ViewAction::make()])
            ->paginated([25, 50]);
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
            'index' => ListChangeRequests::route('/'),
            'create' => CreateChangeRequest::route('/create'),
            'view' => ViewChangeRequest::route('/{record}'),
        ];
    }
}
