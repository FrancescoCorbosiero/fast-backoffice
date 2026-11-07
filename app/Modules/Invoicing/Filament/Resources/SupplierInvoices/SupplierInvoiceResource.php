<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\SupplierInvoices;

use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Invoicing\Filament\Resources\SupplierInvoices\Pages\ListSupplierInvoices;
use App\Modules\Invoicing\Filament\Resources\SupplierInvoices\Pages\ViewSupplierInvoice;
use App\Modules\Invoicing\Models\SupplierInvoice;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SupplierInvoiceResource extends Resource
{
    protected static ?string $model = SupplierInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Fatturazione';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'fattura fornitore';

    protected static ?string $pluralModelLabel = 'Fatture fornitori';

    protected static ?string $slug = 'supplier-invoices';

    public const string FILTER_UNPAID = 'unpaid';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('issued_on', 'desc')
            ->columns([
                TextColumn::make('issued_on')->label('Data')->date('d/m/Y')->sortable(),
                TextColumn::make('supplier_name')->label('Fornitore')->searchable()->weight('bold')->url(fn (SupplierInvoice $record): string => ContactLink::url($record->contact_id)),
                TextColumn::make('number')->label('Numero')->searchable(),
                TextColumn::make('total_cents')->label('Totale')->alignEnd()->formatStateUsing(fn (SupplierInvoice $record): string => $record->toData()->total->format()),
                TextColumn::make('due_on')->label('Scadenza')->date('d/m/Y')->placeholder('—'),
                TextColumn::make('paid')->label('Pagata')->badge()
                    ->state(fn (SupplierInvoice $record): string => $record->toData()->outstanding()->isZero() ? 'sì' : 'residuo '.$record->toData()->outstanding()->format())
                    ->color(fn (SupplierInvoice $record): string => $record->toData()->outstanding()->isZero() ? 'success' : 'warning'),
            ])
            ->filters([
                Filter::make(self::FILTER_UNPAID)->label('Da pagare')->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereColumn('paid_cents', '<', 'total_cents')),
            ])
            ->recordActions([ViewAction::make()])
            ->paginated([25, 50, 100]);
    }

    /**
     * The list showing only what the studio still owes.
     */
    public static function unpaidUrl(): string
    {
        return static::getUrl('index', ['filters' => [self::FILTER_UNPAID => ['isActive' => true]]]);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = SupplierInvoice::query()->whereColumn('paid_cents', '<', 'total_cents')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Fatture fornitori da pagare';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierInvoices::route('/'),
            'view' => ViewSupplierInvoice::route('/{record}'),
        ];
    }
}
