<?php

declare(strict_types=1);

namespace App\Modules\Cash\Filament\Pages;

use App\Modules\Cash\Data\ExpectedFlow;
use App\Modules\Cash\Data\Projection;
use App\Modules\Cash\Filament\Amounts;
use App\Modules\Cash\Queries\CashFlowProjection;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
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
 * What money is expected to move over the coming months, from every
 * module's CashFlowSchedule. Nothing is written here.
 */
class CashFlowProjectionPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Cassa';

    protected static ?string $navigationLabel = 'Previsione di cassa';

    protected static ?string $title = 'Previsione di cassa';

    protected static ?string $slug = 'cash/projection';

    protected static ?int $navigationSort = 40;

    #[Url]
    public int $months = 12;

    private ?Projection $projection = null;

    public function content(Schema $schema): Schema
    {
        $projection = $this->projection();

        return $schema->components([
            Section::make()->columns(4)->components([
                Select::make('months')->label('Orizzonte')->options([3 => '3 mesi', 6 => '6 mesi', 12 => '12 mesi', 24 => '24 mesi'])->live()->selectablePlaceholder(false),
                TextEntry::make('total_in')->label('Entrate attese')->state(Amounts::format($projection->in))->color('success'),
                TextEntry::make('total_out')->label('Uscite attese')->state(Amounts::format($projection->out))->color('danger'),
                TextEntry::make('total_net')->label('Netto')->state(Amounts::format($projection->net()))->color($projection->net()->isNegative() ? 'danger' : 'success')->weight('bold'),
            ]),
            Grid::make(4)->components(array_map(
                fn ($month) => Section::make($month->start->isoFormat('MMM YYYY'))->key('month-'.$month->key())->compact()->components([
                    TextEntry::make('in-'.$month->key())->label('Entrate')->state(Amounts::format($month->in))->color('success'),
                    TextEntry::make('out-'.$month->key())->label('Uscite')->state(Amounts::format($month->out))->color('danger'),
                    TextEntry::make('net-'.$month->key())->label('Netto')->state(Amounts::format($month->net()))->weight('bold')->color($month->net()->isNegative() ? 'danger' : null),
                ]),
                $projection->months,
            )),
            Section::make('Voci')->components([EmbeddedTable::make()]),
        ]);
    }

    public function updatedMonths(): void
    {
        $this->projection = null;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('date')->label('Data')->date('d/m/Y')
                    ->description(fn (array $record): ?string => ($record['overdue'] ?? false) === true ? 'in ritardo' : null)
                    ->color(fn (array $record): ?string => ($record['overdue'] ?? false) === true ? 'danger' : null),
                TextColumn::make('description')->label('Voce')->wrap(),
                TextColumn::make('source')->label('Origine')->badge(),
                TextColumn::make('amount')->label('Importo')->alignEnd()->color(fn (array $record): string => ($record['in'] ?? false) === true ? 'success' : 'danger'),
            ])
            ->emptyStateHeading('Nessun movimento atteso')
            ->emptyStateDescription('Spese ricorrenti, fatture da incassare e rinnovi compariranno qui.')
            ->paginated(false);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $projection = $this->projection();
        $rows = [];

        foreach ($projection->flows as $index => $flow) {
            $rows['flow-'.$index] = [
                'date' => $flow->date->toDateString(),
                'overdue' => $projection->isOverdue($flow),
                'description' => $flow->description,
                'source' => self::sourceLabel($flow),
                'amount' => Amounts::format($flow->amount),
                'in' => $flow->direction->sign() > 0,
            ];
        }

        return $rows;
    }

    private function projection(): Projection
    {
        $months = in_array($this->months, [3, 6, 12, 24], true) ? $this->months : 12;

        return $this->projection ??= app(CashFlowProjection::class)->project(CarbonImmutable::today(), $months);
    }

    private static function sourceLabel(ExpectedFlow $flow): string
    {
        return match ($flow->source) {
            'recurring_expense' => 'spesa ricorrente',
            'receivable' => 'fattura da incassare',
            'recurring_invoice' => 'fattura ricorrente',
            'domain_renewal' => 'rinnovo dominio',
            'hosting_renewal' => 'rinnovo hosting',
            default => str_replace('_', ' ', $flow->source),
        };
    }
}
