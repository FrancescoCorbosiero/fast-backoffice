<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\Movements;

use App\Kernel\Reference;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Filament\Allocations;
use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Filament\Resources\Movements\Pages\CreateMovement;
use App\Modules\Cash\Filament\Resources\Movements\Pages\EditMovement;
use App\Modules\Cash\Filament\Resources\Movements\Pages\ListMovements;
use App\Modules\Cash\Models\Category;
use App\Modules\Cash\Models\Movement;
use App\Modules\Cash\Queries\Accounts;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every movement. Allocated ones (an invoice's payment, a logged renewal)
 * belong to their document and are read-only here (design.md §5.4).
 */
class MovementResource extends Resource
{
    protected static ?string $model = Movement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Cassa';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'movimento';

    protected static ?string $pluralModelLabel = 'Movimenti';

    protected static ?string $slug = 'cash/movements';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->components([
                Select::make('direction')->label('Verso')->options(Direction::options())->required()->live()->default(Direction::Out->value),
                Amounts::input('amount'),
                DatePicker::make('occurred_on')->label('Data')->required()->default(now()),
                Select::make('account_id')->label('Conto')->options(fn (): array => app(Accounts::class)->options())->required(),
                TextInput::make('description')->label('Descrizione')->required()->maxLength(200)->columnSpanFull(),
                Select::make('category_id')->label('Categoria')->searchable()->preload()
                    ->options(fn (Get $get): array => self::categoryOptions($get('direction'))),
                ContactPicker::make('contact_id', [])->label('Controparte'),
                Toggle::make('is_taxable')->label('Conta nei ricavi/costi')->default(true)
                    ->helperText('Spegnilo per giroconti, prelievi personali, versamenti propri: restano nel saldo ma non nelle analisi.'),
                Textarea::make('notes')->label('Note')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_on', 'desc')
            ->columns([
                TextColumn::make('occurred_on')->label('Data')->date('d/m/Y')->sortable(),
                TextColumn::make('description')->label('Descrizione')->searchable()->wrap()
                    ->description(fn (Movement $record): ?string => $record->allocation === null ? null : Allocations::label(Reference::fromString($record->allocation)))
                    ->url(fn (Movement $record): ?string => $record->allocation === null ? null : Allocations::url(Reference::fromString($record->allocation))),
                TextColumn::make('amount')->label('Importo')->alignEnd()
                    ->state(fn (Movement $record): string => Amounts::format($record->signedAmount()))
                    ->color(fn (Movement $record): string => $record->direction->color()),
                TextColumn::make('account.name')->label('Conto'),
                TextColumn::make('category.name')->label('Categoria')->placeholder('—'),
                TextColumn::make('contact')->label('Controparte')->placeholder('—')
                    ->state(fn (Movement $record, ListMovements $livewire): ?string => $livewire->contactName($record->contact_id)),
            ])
            ->filters([
                SelectFilter::make('account_id')->label('Conto')->options(fn (): array => app(Accounts::class)->options(activeOnly: false)),
                SelectFilter::make('direction')->label('Verso')->options(Direction::options()),
                SelectFilter::make('category_id')->label('Categoria')->relationship('category', 'name'),
                Filter::make('period')->schema([
                    DatePicker::make('from')->label('Dal'),
                    DatePicker::make('to')->label('Al'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when(is_string($data['from'] ?? null) && $data['from'] !== '', fn (Builder $query) => $query->where('occurred_on', '>=', $data['from']))
                    ->when(is_string($data['to'] ?? null) && $data['to'] !== '', fn (Builder $query) => $query->where('occurred_on', '<=', $data['to']))),
            ])
            ->recordActions([EditAction::make()->visible(fn (Movement $record): bool => ! $record->isAllocated())])
            ->paginated([25, 50, 100]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['account', 'category']);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Movement && ! $record->isAllocated();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    public static function categoryOptions(mixed $direction): array
    {
        $direction = is_string($direction) ? Direction::tryFrom($direction) : null;
        $options = [];

        foreach (Category::query()->active()->when($direction !== null, fn (Builder $query) => $query->where('kind', $direction?->categoryKind()->value))->orderBy('sort')->orderBy('name')->get(['id', 'name']) as $category) {
            $options[$category->id] = $category->name;
        }

        return $options;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMovements::route('/'),
            'create' => CreateMovement::route('/create'),
            'edit' => EditMovement::route('/{record}/edit'),
        ];
    }
}
