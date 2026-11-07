<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Pages;

use App\Kernel\Dates\Months;
use App\Kernel\Money\Money;
use App\Modules\Cash\Data\LedgerLine;
use App\Modules\Cash\Filament\Allocations;
use App\Modules\Cash\Filament\Components\Amounts;
use App\Modules\Cash\Filament\Resources\Movements\MovementResource;
use App\Modules\Cash\Filament\Resources\Movements\Pages\CreateMovement;
use App\Modules\Cash\Models\Account;
use App\Modules\Cash\Queries\Accounts;
use App\Modules\Cash\Queries\Ledger;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
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

    /** @var array{Money, Money}|null */
    private ?array $balances = null;

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
            Section::make()->columns(4)->components([
                Select::make('account')->label('Conto')->options(fn (): array => app(Accounts::class)->options(activeOnly: false))->live()->selectablePlaceholder(false),
                Select::make('month')->label('Mese')->options(fn (): array => $this->monthOptions())->live()->selectablePlaceholder(false),
                TextEntry::make('opening_balance')->label('Saldo iniziale')->state(fn (): string => $this->balances() === null ? '—' : Amounts::format($this->balances()[0])),
                TextEntry::make('closing_balance')->label('Saldo finale')->weight('bold')->state(fn (): string => $this->balances() === null ? '—' : Amounts::format($this->balances()[1])),
            ]),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $month = $this->from();

        return [
            Action::make('previous')->label('Mese precedente')->icon(Heroicon::OutlinedChevronLeft)->color('gray')
                ->url(static::getUrl(['account' => $this->account, 'month' => Months::subtract($month, 1)->format('Y-m')])),
            Action::make('next')->label('Mese successivo')->icon(Heroicon::OutlinedChevronRight)->iconPosition(IconPosition::After)->color('gray')
                ->url(static::getUrl(['account' => $this->account, 'month' => Months::add($month, 1)->format('Y-m')])),
            $this->newMovementAction(),
        ];
    }

    public function updatedAccount(): void
    {
        $this->balances = null;
        $this->resetTable();
    }

    public function updatedMonth(): void
    {
        $this->balances = null;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('date')->label('Data')->date('d/m/Y'),
                TextColumn::make('description')->label('Descrizione')->wrap()
                    ->description(fn (array $record): ?string => is_string($record['detail'] ?? null) ? $record['detail'] : null)
                    ->url(fn (array $record): ?string => is_string($record['url'] ?? null) ? $record['url'] : null)
                    ->icon(fn (array $record): ?Heroicon => ($record['allocated'] ?? false) === true ? Heroicon::ArrowTopRightOnSquare : null),
                TextColumn::make('amount')->label('Importo')->alignEnd()->color(fn (array $record): string => ($record['in'] ?? false) === true ? 'success' : 'danger'),
                TextColumn::make('balance')->label('Saldo')->alignEnd()->weight('bold'),
            ])
            ->emptyStateHeading('Nessun movimento nel mese')
            ->emptyStateDescription('Manca una riga del conto? Registrala da qui, già sul conto e nel mese giusti.')
            ->emptyStateActions([$this->newMovementAction()])
            ->paginated(false);
    }

    /**
     * The movement form, already on this account and in this month. Built
     * fresh on every call: an Action instance belongs to one place.
     */
    private function newMovementAction(): Action
    {
        return Action::make('new-movement')
            ->label('Nuovo movimento')
            ->icon(Heroicon::OutlinedBanknotes)
            ->url(CreateMovement::urlFor($this->account, $this->from()->isSameMonth(CarbonImmutable::today()) ? CarbonImmutable::today() : $this->from()));
    }

    private function from(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->month.'-01');
    }

    /**
     * The account's balance before the month and after it; null without an account.
     *
     * @return array{Money, Money}|null
     */
    private function balances(): ?array
    {
        if ($this->account === null || $this->account === 0) {
            return null;
        }

        if ($this->balances === null) {
            $account = Account::query()->find($this->account);

            if ($account === null) {
                return null;
            }

            $ledger = app(Ledger::class);
            $this->balances = [$ledger->balanceBefore($account, $this->from()), $ledger->balanceBefore($account, Months::add($this->from(), 1))];
        }

        return $this->balances;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        if ($this->account === null || $this->account === 0) {
            return [];
        }

        $from = $this->from();
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
        $allocation = $line->movement->allocation;
        $detail = array_filter([$allocation === null ? null : Allocations::label($allocation), $line->categoryName, $line->contactName], fn (?string $part): bool => $part !== null);

        return [
            'date' => $line->movement->occurredOn->toDateString(),
            'description' => $line->movement->description,
            'detail' => $detail === [] ? null : implode(' · ', $detail),
            'allocated' => $allocation !== null,
            'url' => $allocation === null ? MovementResource::getUrl('edit', ['record' => $line->movement->id]) : Allocations::url($allocation),
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

        // A month reached with the arrows stays selectable.
        $options[$this->month] ??= $this->from()->isoFormat('MMMM YYYY');

        return $options;
    }
}
