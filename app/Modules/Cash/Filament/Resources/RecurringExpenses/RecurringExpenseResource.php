<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\RecurringExpenses;

use App\Kernel\Dates\Frequency;
use App\Modules\Cash\Actions\LogRecurringExpense;
use App\Modules\Cash\Enums\Direction;
use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Filament\Resources\Movements\MovementResource;
use App\Modules\Cash\Filament\Resources\RecurringExpenses\Pages\CreateRecurringExpense;
use App\Modules\Cash\Filament\Resources\RecurringExpenses\Pages\EditRecurringExpense;
use App\Modules\Cash\Filament\Resources\RecurringExpenses\Pages\ListRecurringExpenses;
use App\Modules\Cash\Models\RecurringExpense;
use App\Modules\Cash\Queries\Accounts;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class RecurringExpenseResource extends Resource
{
    protected static ?string $model = RecurringExpense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Cassa';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'spesa ricorrente';

    protected static ?string $pluralModelLabel = 'Spese ricorrenti';

    protected static ?string $slug = 'cash/recurring-expenses';

    public static function form(Schema $schema): Schema
    {
        $frequencies = [];
        foreach (Frequency::cases() as $frequency) {
            $frequencies[$frequency->value] = $frequency->label();
        }

        return $schema->components([
            Section::make()->columns(2)->components([
                TextInput::make('name')->label('Nome')->required()->maxLength(100),
                Amounts::input('amount'),
                Select::make('frequency')->label('Frequenza')->options($frequencies)->required()->default(Frequency::Monthly->value),
                DatePicker::make('started_on')->label('Prima scadenza')->required()->default(now())
                    ->helperText('Il giorno del mese di questa data è il giorno di ogni scadenza.'),
                Select::make('account_id')->label('Conto')->options(fn (): array => app(Accounts::class)->options()),
                Select::make('category_id')->label('Categoria')->options(fn (): array => MovementResource::categoryOptions(Direction::Out->value)),
                ContactPicker::make('contact_id', [ContactRole::Supplier])->label('Fornitore'),
                Toggle::make('is_active')->label('Attiva')->default(true),
                Textarea::make('notes')->label('Note')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('next_due_on')
            ->columns([
                TextColumn::make('name')->label('Nome')->weight('bold')->searchable(),
                TextColumn::make('amount')->label('Importo')->alignEnd()->state(fn (RecurringExpense $record): string => Amounts::format($record->amount())),
                TextColumn::make('frequency')->label('Frequenza')->formatStateUsing(fn (Frequency $state): string => $state->label()),
                TextColumn::make('next_due_on')->label('Prossima scadenza')->date('d/m/Y')->sortable()
                    ->color(fn (RecurringExpense $record): ?string => $record->is_active && $record->next_due_on->lessThan(CarbonImmutable::today()) ? 'danger' : null)
                    ->description(fn (RecurringExpense $record): ?string => $record->is_active && $record->next_due_on->lessThan(CarbonImmutable::today()) ? 'in ritardo' : null),
                TextColumn::make('account.name')->label('Conto')->placeholder('—'),
                IconColumn::make('is_active')->label('Attiva')->boolean(),
            ])
            ->recordActions([
                Action::make('log')
                    ->label('Registra pagamento')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (RecurringExpense $record): bool => $record->is_active)
                    ->fillForm(fn (RecurringExpense $record): array => ['paid_on' => $record->next_due_on->toDateString(), 'account_id' => $record->account_id])
                    ->schema([
                        DatePicker::make('paid_on')->label('Pagata il')->required(),
                        Select::make('account_id')->label('Dal conto')->options(fn (): array => app(Accounts::class)->options())->required(),
                    ])
                    ->action(function (RecurringExpense $record, array $data): void {
                        try {
                            $movement = app(LogRecurringExpense::class)->log(
                                $record->id,
                                is_string($data['paid_on'] ?? null) ? CarbonImmutable::parse($data['paid_on']) : null,
                                is_numeric($data['account_id'] ?? null) ? (int) $data['account_id'] : null,
                            );
                        } catch (ValidationException $exception) {
                            Notification::make()->title('Pagamento non registrato')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();

                            return;
                        }

                        Notification::make()->title('Pagamento registrato')->body($movement->description.': '.Amounts::format($movement->amount))->success()->send();
                    }),
                EditAction::make(),
            ])
            ->paginated([25, 50]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('account');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecurringExpenses::route('/'),
            'create' => CreateRecurringExpense::route('/create'),
            'edit' => EditRecurringExpense::route('/{record}/edit'),
        ];
    }
}
