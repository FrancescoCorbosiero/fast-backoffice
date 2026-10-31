<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Filament\Resources\Repositories;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Repositories\Actions\SaveRepository;
use App\Modules\Repositories\Data\RepositoryInput;
use App\Modules\Repositories\Enums\RepositoryProvider;
use App\Modules\Repositories\Filament\Resources\Repositories\Pages\ManageRepositories;
use App\Modules\Repositories\Models\Repository;
use App\Modules\Websites\Queries\Websites;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class RepositoryResource extends Resource
{
    protected static ?string $model = Repository::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracketSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Asset';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'repository';

    protected static ?string $pluralModelLabel = 'Repository';

    protected static ?string $slug = 'repositories';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nome')->required()->maxLength(160),
            TextInput::make('url')->label('Indirizzo')->required()->maxLength(500)->placeholder('https://github.com/studio/sito'),
            Select::make('provider')->label('Servizio')->options(RepositoryProvider::options())->placeholder('Dall\'indirizzo'),
            TextInput::make('default_branch')->label('Branch principale')->default('main')->maxLength(120),
            ContactPicker::make('contact_id', [ContactRole::Client])->label('Cliente'),
            Select::make('website_id')->label('Sito')->options(fn (): array => app(Websites::class)->options())->searchable(),
            Textarea::make('notes')->label('Note')->rows(2)->maxLength(2000)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nome')->weight('bold')->searchable()->sortable(),
                TextColumn::make('provider')->label('Servizio')->formatStateUsing(fn (RepositoryProvider $state): string => $state->label()),
                TextColumn::make('url')->label('Indirizzo')->url(fn (Repository $record): string => $record->url, true)->limit(50)->searchable(),
                TextColumn::make('default_branch')->label('Branch'),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function (Repository $record, array $data): Repository {
                        app(SaveRepository::class)->update($record->id, RepositoryInput::fromArray($data));

                        return $record->refresh();
                    }),
                DeleteAction::make()->using(fn (Repository $record) => app(SaveRepository::class)->delete($record->id)),
            ])
            ->paginated([25, 50]);
    }

    public static function canDelete(Model $record): bool
    {
        return true;
    }

    public static function getPages(): array
    {
        return ['index' => ManageRepositories::route('/')];
    }
}
