<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\RecurringInvoices;

use App\Kernel\Dates\Frequency;
use App\Modules\Contacts\Enums\ContactRole;
use App\Modules\Contacts\Filament\Components\ContactPicker;
use App\Modules\Invoicing\Actions\IssueRecurringInvoice;
use App\Modules\Invoicing\Filament\Components\LinesSchema;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Filament\Resources\RecurringInvoices\Pages\CreateRecurringInvoice;
use App\Modules\Invoicing\Filament\Resources\RecurringInvoices\Pages\EditRecurringInvoice;
use App\Modules\Invoicing\Filament\Resources\RecurringInvoices\Pages\ListRecurringInvoices;
use App\Modules\Invoicing\Models\RecurringInvoice;
use BackedEnum;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class RecurringInvoiceResource extends Resource
{
    protected static ?string $model = RecurringInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Fatturazione';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'fattura ricorrente';

    protected static ?string $pluralModelLabel = 'Fatture ricorrenti';

    protected static ?string $slug = 'recurring-invoices';

    public static function form(Schema $schema): Schema
    {
        $frequencies = [];
        foreach (Frequency::cases() as $frequency) {
            $frequencies[$frequency->value] = $frequency->label();
        }

        return $schema->components([
            Section::make()->columns(2)->components([
                TextInput::make('name')->label('Nome')->required()->maxLength(120),
                ContactPicker::make('contact_id', [ContactRole::Client])->label('Cliente')->required(),
                Select::make('frequency')->label('Frequenza')->options($frequencies)->required()->default(Frequency::Monthly->value),
                DatePicker::make('started_on')->label('Prima emissione')->required()->default(today())
                    ->helperText('Il giorno del mese di questa data è il giorno di ogni emissione.'),
                TextInput::make('payment_terms_days')->label('Termini di pagamento (giorni)')->integer()->minValue(0)->maxValue(365)->default(30)->required(),
                Toggle::make('auto_issue')->label('Emetti automaticamente (altrimenti crea una bozza)'),
                Toggle::make('is_active')->label('Attiva')->default(true),
            ]),
            LinesSchema::repeater(),
            Textarea::make('notes')->label('Note')->rows(2)->maxLength(2000),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('next_issue_on')
            ->columns([
                TextColumn::make('name')->label('Nome')->weight('bold')->searchable(),
                TextColumn::make('frequency')->label('Frequenza')->formatStateUsing(fn (Frequency $state): string => $state->label()),
                TextColumn::make('next_issue_on')->label('Prossima emissione')->date('d/m/Y')->sortable()
                    ->color(fn (RecurringInvoice $record): ?string => $record->is_active && $record->next_issue_on->lessThan(today()) ? 'danger' : null),
                TextColumn::make('last_issued_on')->label('Ultima')->date('d/m/Y')->placeholder('mai'),
                IconColumn::make('auto_issue')->label('Automatica')->boolean(),
                IconColumn::make('is_active')->label('Attiva')->boolean(),
            ])
            ->recordActions([
                Action::make('issue')->label('Emetti ora')->icon(Heroicon::OutlinedPlay)
                    ->visible(fn (RecurringInvoice $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->modalDescription(fn (RecurringInvoice $record): string => 'Crea il documento del ciclo del '.$record->next_issue_on->isoFormat('D MMMM YYYY').($record->auto_issue ? ' e lo emette.' : ' come bozza.'))
                    ->action(function (RecurringInvoice $record): void {
                        try {
                            $invoice = app(IssueRecurringInvoice::class)->issue($record->id);
                        } catch (ValidationException $exception) {
                            Notification::make()->title('Non emessa')->body(implode(' ', $exception->validator->errors()->all()))->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()->title($invoice->title().' creata')->success()
                            ->actions([Action::make('open')->label('Apri')->url(InvoiceResource::getUrl('view', ['record' => $invoice->id]))])
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->paginated([25, 50]);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecurringInvoices::route('/'),
            'create' => CreateRecurringInvoice::route('/create'),
            'edit' => EditRecurringInvoice::route('/{record}/edit'),
        ];
    }
}
