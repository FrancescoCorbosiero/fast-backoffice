<?php

declare(strict_types=1);

namespace App\Modules\Domains\Filament\Resources\Domains;

use App\Modules\Cash\Enums\CategoryKind;
use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Queries\Accounts;
use App\Modules\Cash\Queries\Categories;
use App\Modules\Contacts\Data\ContactData;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Filament\Resources\Domains\Pages\CreateDomain;
use App\Modules\Domains\Filament\Resources\Domains\Pages\EditDomain;
use App\Modules\Domains\Filament\Resources\Domains\Pages\ListDomains;
use App\Modules\Domains\Filament\Resources\Domains\Pages\ViewDomain;
use App\Modules\Domains\Models\Domain;
use App\Modules\Websites\Enums\WebsiteStatus;
use App\Modules\Websites\Filament\Components\WebsiteLink;
use App\Modules\Websites\Queries\Websites;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAtSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Asset';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'dominio';

    protected static ?string $pluralModelLabel = 'Domini';

    protected static ?string $slug = 'domains';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->components([
                TextInput::make('name')->label('Dominio')->required()->maxLength(253)->placeholder('esempio.it'),
                ContactPicker::make('contact_id', [ContactRole::Client])->label('Cliente')->required()->live(),
                Select::make('website_id')->label('Sito')->searchable()
                    ->options(fn (Get $get): array => self::websiteOptions($get('contact_id')))
                    ->live()
                    ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => self::fillContactFromWebsite($state, $get, $set)),
                TextInput::make('registrar')->label('Registrar')->maxLength(120),
                DatePicker::make('registered_on')->label('Registrato il'),
                DatePicker::make('expires_on')->label('Scade il')->required(),
            ]),
            Section::make('Rinnovo')->columns(3)->components([
                TextInput::make('renewal_months')->label('Ogni (mesi)')->integer()->minValue(1)->maxValue(120)->default(12)->required(),
                Toggle::make('auto_renew')->label('Rinnovo automatico presso il registrar')->default(true)->inline(false),
                Amounts::input('renewal_cost', 'Costo per rinnovo')->required(false),
                Select::make('account_id')->label('Pagato dal conto')->options(fn (): array => app(Accounts::class)->options()),
                Select::make('category_id')->label('Categoria di spesa')->options(fn (): array => app(Categories::class)->options(CategoryKind::Expense)),
            ]),
            Textarea::make('notes')->label('Note')->rows(2)->maxLength(2000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $today = Date::today();

        return $table
            ->defaultSort('expires_on')
            ->columns([
                TextColumn::make('name')->label('Dominio')->weight('bold')->searchable()->sortable(),
                TextColumn::make('contact_id')->label('Cliente')
                    ->formatStateUsing(fn (int $state, Table $table): string => self::contactNames($table)[$state] ?? '#'.$state)
                    ->url(fn (Domain $record): string => ContactLink::url($record->contact_id)),
                TextColumn::make('website_id')->label('Sito')->placeholder('—')
                    ->formatStateUsing(fn (int $state): string => self::websiteNames()[$state] ?? '#'.$state)
                    ->url(fn (Domain $record): ?string => $record->website_id === null ? null : WebsiteLink::url($record->website_id)),
                TextColumn::make('registrar')->label('Registrar')->placeholder('—'),
                TextColumn::make('expires_on')->label('Scade il')->date('d/m/Y')->sortable()
                    ->color(fn (Domain $record): ?string => $record->toData()->isExpired($today) ? 'danger' : ($record->toData()->expiresWithin($today, 30) ? 'warning' : null)),
                IconColumn::make('auto_renew')->label('Auto')->boolean(),
                TextColumn::make('renewal_cost_cents')->label('Costo')->alignEnd()->placeholder('—')->formatStateUsing(fn (Domain $record): string => $record->renewalCost()?->format() ?? '—'),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (DomainStatus $state): string => $state->label())->color(fn (DomainStatus $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(DomainStatus::options())->default(DomainStatus::Active->value),
                Filter::make('expiring')->label('In scadenza entro 30 giorni')
                    ->query(fn (Builder $query): Builder => $query->where('status', DomainStatus::Active->value)->where('expires_on', '<=', $today->addDays(30)->toDateString())),
            ])
            ->recordActions([ViewAction::make()])
            ->paginated([25, 50]);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * The sites to choose from: the client's own when one is chosen, every
     * active site otherwise. The repository form does the same.
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
     * The clients of the domains on the current page, resolved once.
     *
     * @return array<int, string>
     */
    private static function contactNames(Table $table): array
    {
        $records = $table->getRecords();
        $ids = [];
        foreach ($records instanceof Collection ? $records : $records->items() as $record) {
            if ($record instanceof Domain) {
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

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'registrar'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (! $record instanceof Domain) {
            return [];
        }

        return array_filter([
            'Scade il' => $record->expires_on->format('d/m/Y'),
            'Registrar' => $record->registrar,
            'Stato' => $record->status->label(),
        ], fn (?string $value): bool => $value !== null);
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return static::getUrl('view', ['record' => $record]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
            'create' => CreateDomain::route('/create'),
            'view' => ViewDomain::route('/{record}'),
            'edit' => EditDomain::route('/{record}/edit'),
        ];
    }
}
