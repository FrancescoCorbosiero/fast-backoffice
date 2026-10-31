<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\PaymentRequests;

use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Invoicing\Enums\PaymentRequestStatus;
use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Invoicing\Filament\Resources\PaymentRequests\Pages\CreatePaymentRequest;
use App\Modules\Invoicing\Filament\Resources\PaymentRequests\Pages\ListPaymentRequests;
use App\Modules\Invoicing\Filament\Resources\PaymentRequests\Pages\ViewPaymentRequest;
use App\Modules\Invoicing\Models\PaymentRequest;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PaymentRequestResource extends Resource
{
    protected static ?string $model = PaymentRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Fatturazione';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'richiesta di pagamento';

    protected static ?string $pluralModelLabel = 'Richieste di pagamento';

    protected static ?string $slug = 'payment-requests';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->components([
                ContactPicker::make('contact_id', [ContactRole::Client])->label('Cliente')->required(),
                DatePicker::make('issued_on')->label('Data')->default(today())->required(),
                TextInput::make('payment_terms_days')->label('Termini (giorni)')->integer()->minValue(0)->maxValue(365),
            ]),
            LinesSchema::repeater(),
            Textarea::make('notes')->label('Note')->rows(2)->maxLength(2000),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number_text')->label('Numero')->weight('bold')->searchable(),
                TextColumn::make('customer_name')->label('Cliente')->searchable(),
                TextColumn::make('issued_on')->label('Data')->date('d/m/Y')->sortable(),
                TextColumn::make('due_on')->label('Scadenza')->date('d/m/Y')->placeholder('—'),
                TextColumn::make('total_cents')->label('Totale')->alignEnd()->formatStateUsing(fn (PaymentRequest $record): string => $record->toData()->total->format()),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (PaymentRequestStatus $state): string => $state->label())->color(fn (PaymentRequestStatus $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(array_combine(array_map(fn (PaymentRequestStatus $s): string => $s->value, PaymentRequestStatus::cases()), array_map(fn (PaymentRequestStatus $s): string => $s->label(), PaymentRequestStatus::cases()))),
            ])
            ->recordActions([ViewAction::make()])
            ->paginated([25, 50]);
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
            'index' => ListPaymentRequests::route('/'),
            'create' => CreatePaymentRequest::route('/create'),
            'view' => ViewPaymentRequest::route('/{record}'),
        ];
    }
}
