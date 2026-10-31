<?php

declare(strict_types=1);

namespace App\Modules\Websites\Filament\Resources\Websites;

use App\Kernel\Dates\Frequency;
use App\Modules\Cash\Enums\CategoryKind;
use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Queries\Accounts;
use App\Modules\Cash\Queries\Categories;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactPicker;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
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
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nome')->weight('bold')->searchable()->sortable(),
                TextColumn::make('host')->label('Host')->searchable(),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (WebsiteStatus $state): string => $state->label())->color(fn (WebsiteStatus $state): string => $state->color()),
                IconColumn::make('is_up')->label('Online')->boolean()->placeholder('—'),
                TextColumn::make('hosting_renews_on')->label('Rinnovo hosting')->date('d/m/Y')->placeholder('—')->sortable(),
                TextColumn::make('hosting_cost_cents')->label('Costo')->alignEnd()->placeholder('—')->formatStateUsing(fn (Website $record): string => $record->hostingCost()?->format() ?? '—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(WebsiteStatus::options()),
            ])
            ->recordActions([ViewAction::make()])
            ->paginated([25, 50]);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
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
