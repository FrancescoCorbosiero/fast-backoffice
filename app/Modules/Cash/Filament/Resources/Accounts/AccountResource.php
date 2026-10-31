<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Resources\Accounts;

use App\Kernel\Money\Money;
use App\Modules\Cash\Enums\AccountType;
use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Modules\Cash\Filament\Resources\Accounts\Pages\EditAccount;
use App\Modules\Cash\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Modules\Cash\Models\Account;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Cassa';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'conto';

    protected static ?string $pluralModelLabel = 'Conti';

    protected static ?string $slug = 'cash/accounts';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->selectRaw(
            "cash_accounts.*, (select coalesce(sum(case direction when 'in' then amount_cents else -amount_cents end), 0) from cash_movements where cash_movements.account_id = cash_accounts.id) as movements_balance",
        );
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->components([
                TextInput::make('name')->label('Nome')->required()->maxLength(60),
                Select::make('type')->label('Tipo')->options(AccountType::options())->required(),
                TextInput::make('iban')->label('IBAN')->maxLength(34),
                TextInput::make('currency')->label('Valuta')->default('EUR')->maxLength(3)->regex('/^[A-Z]{3}$/')->required(),
                TextInput::make('opening_balance')->label('Saldo iniziale')->prefix('€')->default('0.00')
                    ->rules(['regex:/^-?\d+([.,]\d{1,2})?$/'])->required(),
                DatePicker::make('opened_on')->label('Dal')->default(now()->startOfYear())->required(),
                Toggle::make('is_active')->label('Attivo')->default(true),
                TextInput::make('sort')->label('Ordine')->integer()->default(0),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label('Nome')->weight('bold')->searchable(),
                TextColumn::make('type')->label('Tipo')->formatStateUsing(fn (AccountType $state): string => $state->label()),
                TextColumn::make('balance')->label('Saldo')->alignEnd()
                    ->state(fn (Account $record): string => Amounts::format($record->openingBalance()->plus(new Money(self::movementsBalance($record), $record->currency)))),
                TextColumn::make('iban')->label('IBAN')->fontFamily('mono')->placeholder('—')->toggleable(),
                IconColumn::make('is_active')->label('Attivo')->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->paginated(false);
    }

    private static function movementsBalance(Account $record): int
    {
        $raw = $record->getAttribute('movements_balance');

        return is_numeric($raw) ? (int) $raw : 0;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccounts::route('/'),
            'create' => CreateAccount::route('/create'),
            'edit' => EditAccount::route('/{record}/edit'),
        ];
    }
}
