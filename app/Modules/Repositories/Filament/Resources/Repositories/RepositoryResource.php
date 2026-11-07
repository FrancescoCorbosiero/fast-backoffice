<?php

declare(strict_types=1);

namespace App\Modules\Repositories\Filament\Resources\Repositories;

use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Repositories\Actions\SaveRepository;
use App\Modules\Repositories\Enums\RepositoryProvider;
use App\Modules\Repositories\Filament\Resources\Repositories\Pages\ManageRepositories;
use App\Modules\Repositories\Models\Repository;
use App\Modules\Websites\Enums\WebsiteStatus;
use App\Modules\Websites\Filament\Components\WebsiteLink;
use App\Modules\Websites\Queries\Websites;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
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
            ContactPicker::make('contact_id', [ContactRole::Client])->label('Cliente')->live(),
            Select::make('website_id')->label('Sito')->searchable()
                ->options(fn (Get $get): array => self::websiteOptions($get('contact_id')))
                ->live()
                ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => self::fillContactFromWebsite($state, $get, $set)),
            Textarea::make('notes')->label('Note')->rows(2)->maxLength(2000)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nome')->weight('bold')->searchable()->sortable(),
                TextColumn::make('website_id')->label('Sito')->placeholder('—')
                    ->formatStateUsing(fn (int $state): string => self::websiteNames()[$state] ?? '#'.$state)
                    ->url(fn (Repository $record): ?string => $record->website_id === null ? null : WebsiteLink::url($record->website_id)),
                TextColumn::make('contact_id')->label('Cliente')->placeholder('—')
                    ->formatStateUsing(fn (int $state, Table $table): string => self::contactNames($table)[$state] ?? '#'.$state)
                    ->url(fn (Repository $record): ?string => $record->contact_id === null ? null : ContactLink::url($record->contact_id)),
                TextColumn::make('provider')->label('Servizio')->formatStateUsing(fn (RepositoryProvider $state): string => $state->label()),
                TextColumn::make('url')->label('Indirizzo')->url(fn (Repository $record): string => $record->url, true)->limit(50)->searchable(),
                TextColumn::make('default_branch')->label('Branch'),
            ])
            ->filters([
                SelectFilter::make('website_id')->label('Sito')->options(fn (): array => app(Websites::class)->options()),
            ])
            ->recordActions([
                RepositoryActions::edit(),
                // What SaveRepository refuses (a repository still linked to a
                // pipeline) is said in its own words instead of an error page.
                DeleteAction::make()
                    ->using(function (Repository $record): bool {
                        try {
                            app(SaveRepository::class)->delete($record->id);
                        } catch (ValidationException $exception) {
                            Notification::make()->title('Repository non eliminato')->body(implode(' ', $exception->validator->errors()->all()))->danger()->persistent()->send();

                            return false;
                        }

                        return true;
                    })
                    ->failureNotification(null),
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

    /**
     * The sites to choose from: the client's own when one is chosen, every
     * active site otherwise. The domain form does the same.
     *
     * @return array<int, string>
     */
    public static function websiteOptions(mixed $contactId): array
    {
        $websites = app(Websites::class);

        if (! is_numeric($contactId) || (int) $contactId <= 0) {
            return $websites->options();
        }

        $options = [];
        foreach ($websites->forContact((int) $contactId) as $website) {
            if ($website->status === WebsiteStatus::Active) {
                $options[$website->id] = $website->name.' · '.$website->host;
            }
        }

        return $options;
    }

    /**
     * Picking a site before the client fills the client in: the two never disagree.
     */
    public static function fillContactFromWebsite(mixed $websiteId, Get $get, Set $set): void
    {
        if (! is_numeric($websiteId) || is_numeric($get('contact_id'))) {
            return;
        }

        $website = app(Websites::class)->find((int) $websiteId);

        if ($website !== null) {
            $set('contact_id', $website->contactId);
        }
    }

    /**
     * The clients of the repositories on the current page, resolved once.
     *
     * @return array<int, string>
     */
    private static function contactNames(Table $table): array
    {
        $records = $table->getRecords();
        $ids = [];
        foreach ($records instanceof Collection ? $records : $records->items() as $record) {
            if ($record instanceof Repository && $record->contact_id !== null) {
                $ids[] = $record->contact_id;
            }
        }

        return once(fn (): array => array_map(fn (ContactData $contact): string => $contact->displayName, app(FindContact::class)->byIds($ids)));
    }

    /**
     * @return array<int, string>
     */
    private static function websiteNames(): array
    {
        return once(function (): array {
            $names = [];
            foreach (app(Websites::class)->active() as $website) {
                $names[$website->id] = $website->name;
            }

            return $names;
        });
    }
}
