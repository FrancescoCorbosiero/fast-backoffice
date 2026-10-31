<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Filament\Resources\Quotes;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Quotes\Data\QuoteInput;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Modules\Quotes\Filament\Resources\Quotes\Pages\EditQuote;
use App\Modules\Quotes\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Modules\Quotes\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Modules\Quotes\Models\Quote;
use App\Modules\Quotes\Queries\Quotes;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use UnitEnum;

class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Fatturazione';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'preventivo';

    protected static ?string $pluralModelLabel = 'Preventivi';

    protected static ?string $slug = 'quotes';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->components([
                ContactPicker::make('contact_id', [ContactRole::Client])->label('Cliente')->required(),
                DatePicker::make('issued_on')->label('Data')->default(today())->required(),
                DatePicker::make('valid_until')->label('Valido fino al')->default(today()->addDays(QuoteInput::DEFAULT_VALIDITY_DAYS))->required()->afterOrEqual('issued_on'),
                TextInput::make('subject')->label('Oggetto')->maxLength(200)->columnSpanFull(),
            ]),
            LinesSchema::repeater(),
            Textarea::make('notes')->label('Note')->rows(3)->maxLength(2000),
        ]);
    }

    public static function table(Table $table): Table
    {
        $today = Date::today();

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number_text')->label('Numero')->weight('bold')->searchable(),
                TextColumn::make('customer_name')->label('Cliente')->searchable(),
                TextColumn::make('subject')->label('Oggetto')->placeholder('—')->limit(40)->searchable(),
                TextColumn::make('issued_on')->label('Data')->date('d/m/Y')->sortable(),
                TextColumn::make('valid_until')->label('Valido fino al')->date('d/m/Y')->sortable(),
                TextColumn::make('total_cents')->label('Totale')->alignEnd()->formatStateUsing(fn (Quote $record): string => $record->toData()->total->format()),
                TextColumn::make('status')->label('Stato')->badge()
                    ->formatStateUsing(fn (Quote $record): string => $record->toData()->statusOn($today)->label())
                    ->color(fn (Quote $record): string => $record->toData()->statusOn($today)->color()),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(QuoteStatus::options())
                    ->query(fn (Builder $query, array $data): Builder => self::filterByStatus($query, $data, $today)),
                Filter::make('expiring')->label('In scadenza entro 7 giorni')
                    ->query(fn (Builder $query): Builder => Quotes::openOn($query, $today)->where('valid_until', '<=', $today->addDays(7)->toDateString())),
            ])
            ->recordActions([ViewAction::make()])
            ->paginated([25, 50]);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Quote && $record->status->isOpen();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
            'create' => CreateQuote::route('/create'),
            'view' => ViewQuote::route('/{record}'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
    }

    /**
     * @param  Builder<Quote>  $query
     * @param  array<array-key, mixed>  $data
     * @return Builder<Quote>
     */
    private static function filterByStatus(Builder $query, array $data, CarbonImmutable $today): Builder
    {
        $status = QuoteStatus::tryFrom(is_string($data['value'] ?? null) ? $data['value'] : '');

        return match ($status) {
            null => $query,
            QuoteStatus::Expired => $query->whereIn('status', [QuoteStatus::Draft->value, QuoteStatus::Sent->value])->where('valid_until', '<', $today->toDateString()),
            QuoteStatus::Draft, QuoteStatus::Sent => $query->where('status', $status->value)->where('valid_until', '>=', $today->toDateString()),
            default => $query->where('status', $status->value),
        };
    }
}
