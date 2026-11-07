<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\Invoices;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Invoicing\Enums\DocumentType;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\PaymentState;
use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\RecurringInvoice;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Fatturazione';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'fattura';

    protected static ?string $pluralModelLabel = 'Fatture';

    protected static ?string $slug = 'invoices';

    protected static ?string $recordTitleAttribute = 'number_text';

    public const string FILTER_OUTSTANDING = 'outstanding';

    public const string FILTER_OVERDUE = 'overdue';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->components([
                ContactPicker::make('contact_id', [ContactRole::Client])->label('Cliente')->required(),
                TextInput::make('payment_terms_days')->label('Termini di pagamento (giorni)')->integer()->minValue(0)->maxValue(365),
            ]),
            LinesSchema::repeater(),
            Textarea::make('notes')->label('Note sul documento')->rows(2)->maxLength(2000),
        ]);
    }

    public static function table(Table $table): Table
    {
        $today = CarbonImmutable::today();

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number_text')->label('Numero')->weight('bold')->placeholder('bozza')->searchable(),
                TextColumn::make('type')->label('Tipo')->formatStateUsing(fn (DocumentType $state): string => $state->label()),
                TextColumn::make('customer_name')->label('Cliente')->searchable()->url(fn (Invoice $record): string => ContactLink::url($record->contact_id)),
                TextColumn::make('issued_on')->label('Data')->date('d/m/Y')->sortable()->placeholder('—'),
                TextColumn::make('total_cents')->label('Totale')->alignEnd()->formatStateUsing(fn (Invoice $record): string => $record->toData()->total->format()),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())->color(fn (InvoiceStatus $state): string => $state->color()),
                TextColumn::make('payment')->label('Incasso')->badge()
                    ->state(fn (Invoice $record): ?string => self::paymentState($record, $today)?->label())
                    ->color(fn (Invoice $record): ?string => self::paymentState($record, $today)?->color())
                    ->placeholder('—'),
                TextColumn::make('to_send')->label('Da inviare entro')
                    ->state(fn (Invoice $record): ?string => self::deadline($record, $today))
                    ->color('warning')->placeholder(''),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(InvoiceStatus::options()),
                SelectFilter::make('type')->label('Tipo')->options([DocumentType::Invoice->value => 'Fatture', DocumentType::CreditNote->value => 'Note di credito']),
                SelectFilter::make('payment')->label('Incasso')
                    ->options([self::FILTER_OUTSTANDING => 'Da incassare', self::FILTER_OVERDUE => 'Scadute'])
                    ->query(fn (Builder $query, array $data): Builder => self::applyPaymentFilter($query, $data['value'] ?? null, $today)),
                SelectFilter::make('recurring_invoice_id')->label('Fattura ricorrente')
                    ->options(fn (): array => RecurringInvoice::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (Invoice $record): bool => ! $record->status->isIssued()),
            ])
            ->paginated([25, 50, 100]);
    }

    /**
     * The list filtered the way a dashboard figure counts: open invoices,
     * or the ones past their due date.
     */
    public static function paymentFilterUrl(string $filter): string
    {
        return static::getUrl('index', ['filters' => ['payment' => ['value' => $filter]]]);
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private static function applyPaymentFilter(Builder $query, mixed $value, CarbonImmutable $today): Builder
    {
        if ($value !== self::FILTER_OUTSTANDING && $value !== self::FILTER_OVERDUE) {
            return $query;
        }

        $query->outstanding()->where('type', DocumentType::Invoice->value);

        return $value === self::FILTER_OVERDUE ? $query->whereDate('due_on', '<', $today->toDateString()) : $query;
    }

    public static function canEdit(Model $record): bool
    {
        // A rejected invoice counts as never issued (sdi.md §6): it is corrected and reissued.
        return $record instanceof Invoice && (! $record->status->isIssued() || $record->status === InvoiceStatus::Rejected);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    /**
     * The customer's name is the snapshot taken on the document, so a renamed contact still finds the invoices it had.
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number_text', 'customer_name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        // A draft has no number yet: "Fattura (bozza)" instead of the bare model label.
        return $record instanceof Invoice ? $record->toData()->title() : parent::getGlobalSearchResultTitle($record);
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (! $record instanceof Invoice) {
            return [];
        }

        return [
            'Cliente' => $record->customer_name,
            'Totale' => $record->toData()->total->format(),
            'Stato' => $record->status->label(),
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return static::getUrl('view', ['record' => $record]);
    }

    private static function paymentState(Invoice $record, CarbonImmutable $today): ?PaymentState
    {
        if (! $record->status->isOutstanding() || $record->type !== DocumentType::Invoice) {
            return null;
        }

        return $record->toData()->paymentState($today);
    }

    private static function deadline(Invoice $record, CarbonImmutable $today): ?string
    {
        $days = $record->toData()->daysLeftToSend($today);

        if ($days === null) {
            return null;
        }

        return $days < 0 ? 'in ritardo di '.abs($days).' giorni' : "{$days} giorni";
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            'view' => ViewInvoice::route('/{record}'),
            'edit' => EditInvoice::route('/{record}/edit'),
        ];
    }
}
