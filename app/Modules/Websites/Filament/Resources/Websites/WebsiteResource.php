<?php

declare(strict_types=1);

namespace App\Modules\Websites\Filament\Resources\Websites;

use App\Kernel\Dates\Frequency;
use App\Modules\Cash\Enums\CategoryKind;
use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Queries\Accounts;
use App\Modules\Cash\Queries\Categories;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Websites\Enums\WebsiteStatus;
use App\Modules\Websites\Filament\Resources\Websites\Pages\CreateWebsite;
use App\Modules\Websites\Filament\Resources\Websites\Pages\EditWebsite;
use App\Modules\Websites\Filament\Resources\Websites\Pages\ListWebsites;
use App\Modules\Websites\Filament\Resources\Websites\Pages\ViewWebsite;
use App\Modules\Websites\Models\Website;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use UnitEnum;

class WebsiteResource extends Resource
{
    protected static ?string $model = Website::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Asset';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'sito';

    protected static ?string $pluralModelLabel = 'Siti';

    protected static ?string $slug = 'websites';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $frequencies = [];
        foreach (Frequency::cases() as $frequency) {
            $frequencies[$frequency->value] = $frequency->label();
        }

        return $schema->components([
            Section::make()->columns(2)->components([
                TextInput::make('name')->label('Nome')->required()->maxLength(120),
                ContactPicker::make('contact_id', [ContactRole::Client])->label('Cliente')->required(),
                TextInput::make('url')->label('Indirizzo')->url()->required()->maxLength(500)->placeholder('https://www.esempio.it')->columnSpanFull(),
                Toggle::make('uptime_enabled')->label('Controlla che sia online')->default(true),
            ]),
            Section::make('Hosting')->description('Compila se lo studio paga l\'hosting: la scadenza entra nelle previsioni di cassa e "Registra rinnovo" sposta la data.')->columns(3)->components([
                TextInput::make('hosting_provider')->label('Fornitore')->maxLength(120),
                DatePicker::make('hosting_renews_on')->label('Prossimo rinnovo'),
                Select::make('hosting_frequency')->label('Cadenza')->options($frequencies),
                Amounts::input('hosting_cost', 'Costo per rinnovo')->required(false),
                Select::make('hosting_account_id')->label('Pagato dal conto')->options(fn (): array => app(Accounts::class)->options()),
                Select::make('hosting_category_id')->label('Categoria di spesa')->options(fn (): array => app(Categories::class)->options(CategoryKind::Expense)),
            ]),
            Textarea::make('notes')->label('Note')->rows(2)->maxLength(2000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $today = Date::today();

        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nome')->weight('bold')->searchable()->sortable(),
                TextColumn::make('contact_id')->label('Cliente')
                    ->formatStateUsing(fn (int $state) => self::contactNames($table)[$state] ?? '—')
                    ->url(fn (Website $record): string => ContactLink::url($record->contact_id)),
                TextColumn::make('host')->label('Host')->searchable()->url(fn (Website $record): string => $record->url, true),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (WebsiteStatus $state): string => $state->label())->color(fn (WebsiteStatus $state): string => $state->color()),
                IconColumn::make('is_up')->label('Online')->boolean()->placeholder('—'),
                TextColumn::make('hosting_renews_on')->label('Rinnovo hosting')->date('d/m/Y')->placeholder('—')->sortable(),
                TextColumn::make('hosting_cost_cents')->label('Costo')->alignEnd()->placeholder('—')->formatStateUsing(fn (Website $record): string => $record->hostingCost()?->format() ?? '—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(WebsiteStatus::options()),
                Filter::make('down')->label('Giù adesso')
                    ->query(fn (Builder $query): Builder => $query->where('status', WebsiteStatus::Active->value)->where('uptime_enabled', true)->whereNotNull('down_since')),
                Filter::make('hosting_due')->label('Rinnovo hosting entro 30 giorni')
                    ->query(fn (Builder $query): Builder => $query->where('status', WebsiteStatus::Active->value)->where('hosting_renews_on', '<=', $today->addDays(30)->toDateString())),
            ])
            ->recordActions([ViewAction::make()])
            ->paginated([25, 50]);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * The clients of the page's rows, one query for the page.
     *
     * @return array<int, string>
     */
    private static function contactNames(Table $table): array
    {
        return once(function () use ($table): array {
            $records = $table->getRecords();
            $ids = [];
            foreach ($records instanceof Collection ? $records : $records->items() as $record) {
                if ($record instanceof Website) {
                    $ids[] = $record->contact_id;
                }
            }

            return array_map(fn ($contact): string => $contact->displayName, app(FindContact::class)->byIds($ids));
        });
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'host'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (! $record instanceof Website) {
            return [];
        }

        return [
            'Host' => $record->host,
            'Stato' => $record->status->label(),
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return static::getUrl('view', ['record' => $record]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebsites::route('/'),
            'create' => CreateWebsite::route('/create'),
            'view' => ViewWebsite::route('/{record}'),
            'edit' => EditWebsite::route('/{record}/edit'),
        ];
    }
}
