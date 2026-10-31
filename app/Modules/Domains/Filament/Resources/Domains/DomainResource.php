<?php

declare(strict_types=1);

namespace App\Modules\Domains\Filament\Resources\Domains;

use App\Modules\Cash\Enums\CategoryKind;
use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Queries\Accounts;
use App\Modules\Cash\Queries\Categories;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Filament\Resources\Domains\Pages\CreateDomain;
use App\Modules\Domains\Filament\Resources\Domains\Pages\EditDomain;
use App\Modules\Domains\Filament\Resources\Domains\Pages\ListDomains;
use App\Modules\Domains\Filament\Resources\Domains\Pages\ViewDomain;
use App\Modules\Domains\Models\Domain;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->components([
                TextInput::make('name')->label('Dominio')->required()->maxLength(253)->placeholder('esempio.it'),
                ContactPicker::make('contact_id', [ContactRole::Client])->label('Cliente')->required(),
                Select::make('website_id')->label('Sito')->options(fn (): array => app(Websites::class)->options())->searchable(),
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
                TextColumn::make('registrar')->label('Registrar')->placeholder('—'),
                TextColumn::make('expires_on')->label('Scade il')->date('d/m/Y')->sortable()
                    ->color(fn (Domain $record): ?string => $record->toData()->isExpired($today) ? 'danger' : ($record->toData()->expiresWithin($today, 30) ? 'warning' : null)),
                IconColumn::make('auto_renew')->label('Auto')->boolean(),
                TextColumn::make('renewal_cost_cents')->label('Costo')->alignEnd()->placeholder('—')->formatStateUsing(fn (Domain $record): string => $record->renewalCost()?->format() ?? '—'),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (DomainStatus $state): string => $state->label())->color(fn (DomainStatus $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(DomainStatus::options()),
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
