<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Pages;

use App\Kernel\Dates\Months;
use App\Modules\Cash\Data\LedgerLine;
use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Queries\Accounts;
use App\Modules\Cash\Queries\Ledger;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * One account's statement for one month, with the running balance.
 */
class StatementPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Cassa';

    protected static ?string $navigationLabel = 'Estratto conto';

    protected static ?string $title = 'Estratto conto';

    protected static ?string $slug = 'cash/statement';

    protected static ?int $navigationSort = 11;

    #[Url]
    public ?int $account = null;

    #[Url]
    public string $month = '';

    public function mount(): void
    {
        if ($this->month === '' || preg_match('/^\d{4}-\d{2}$/', $this->month) !== 1) {
            $this->month = CarbonImmutable::today()->format('Y-m');
        }

        $this->account ??= (int) (array_key_first(app(Accounts::class)->options(activeOnly: false)) ?? 0);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->components([
                Select::make('account')->label('Conto')->options(fn (): array => app(Accounts::class)->options(activeOnly: false))->live()->selectablePlaceholder(false),
                Select::make('month')->label('Mese')->options($this->monthOptions())->live()->selectablePlaceholder(false),
            ]),
            EmbeddedTable::make(),
        ]);
    }

    public function updatedAccount(): void
    {
        $this->resetTable();
    }

    public function updatedMonth(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('date')->label('Data')->date('d/m/Y'),
                TextColumn::make('description')->label('Descrizione')->wrap()->description(fn (array $record): ?string => is_string($record['detail'] ?? null) ? $record['detail'] : null),
                TextColumn::make('amount')->label('Importo')->alignEnd()->color(fn (array $record): string => ($record['in'] ?? false) === true ? 'success' : 'danger'),
                TextColumn::make('balance')->label('Saldo')->alignEnd()->weight('bold'),
            ])
            ->emptyStateHeading('Nessun movimento nel mese')
            ->paginated(false);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        if ($this->account === null || $this->account === 0) {
            return [];
        }

        $from = CarbonImmutable::parse($this->month.'-01');
        $rows = [];

        foreach (app(Ledger::class)->statement($this->account, $from, $from->endOfMonth()) as $line) {
            $rows['line-'.$line->movement->id] = self::row($line);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(LedgerLine $line): array
    {
        $detail = array_filter([$line->categoryName, $line->contactName], fn (?string $part): bool => $part !== null);

        return [
            'date' => $line->movement->occurredOn->toDateString(),
            'description' => $line->movement->description,
            'detail' => $detail === [] ? null : implode(' · ', $detail),
            'amount' => Amounts::format($line->movement->signedAmount()),
            'in' => $line->movement->direction->sign() > 0,
            'balance' => Amounts::format($line->balanceAfter),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function monthOptions(): array
    {
        $options = [];
        $month = CarbonImmutable::today()->startOfMonth();

        for ($i = 0; $i < 24; $i++) {
            $options[$month->format('Y-m')] = $month->isoFormat('MMMM YYYY');
            $month = Months::subtract($month, 1);
        }

        return $options;
    }
}
