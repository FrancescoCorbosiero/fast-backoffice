<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Pages;

use App\Modules\Cash\Queries\Accounts;
use App\Modules\Files\Queries\ReadFile;
use App\Modules\Invoicing\Actions\CreateCreditNote;
use App\Modules\Invoicing\Actions\ExportXml;
use App\Modules\Invoicing\Actions\IssueInvoice;
use App\Modules\Invoicing\Actions\MarkSentManually;
use App\Modules\Invoicing\Actions\RecordPayment;
use App\Modules\Invoicing\Actions\RemovePayment;
use App\Modules\Invoicing\Data\DocumentTotals;
use App\Modules\Invoicing\Data\PaymentData;
use App\Modules\Invoicing\Data\PaymentInput;
use App\Modules\Invoicing\DocumentContext;
use App\Modules\Invoicing\Enums\DocumentType;
use App\Modules\Invoicing\Filament\DocumentInfolist;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Queries\InvoicePayments;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The document page: its frozen content once issued, and the actions of
 * its lifecycle (design.md §5.3).
 */
class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();

        return $record instanceof Invoice ? $record->toData()->title() : parent::getTitle();
    }

    public function content(Schema $schema): Schema
    {
        $invoice = $this->invoice();
        $data = $invoice->toData();
        $today = CarbonImmutable::today();
        $computed = $this->draftTotals($invoice);

        $header = [
            TextEntry::make('h_status')->label('Stato')->state($invoice->status->label())->badge()->color($invoice->status->color()),
            TextEntry::make('h_date')->label('Data')->state($data->issuedOn?->isoFormat('D MMM YYYY') ?? 'alla emissione'),
            TextEntry::make('h_due')->label('Scadenza')->state($data->dueOn?->isoFormat('D MMM YYYY') ?? '—'),
        ];

        if ($invoice->status->isOutstanding() && $invoice->type === DocumentType::Invoice) {
            $state = $data->paymentState($today);
            $header[] = TextEntry::make('h_payment')->label('Incasso')->state($state->label().' · residuo '.$data->outstanding()->format())->badge()->color($state->color());
        }

        if (($days = $data->daysLeftToSend($today)) !== null) {
            $header[] = TextEntry::make('h_deadline')->label('Da inviare a SdI')->state($days < 0 ? 'in ritardo di '.abs($days).' giorni' : "entro {$days} giorni")->color($days < 3 ? 'danger' : 'warning');
        }

        if ($invoice->related_invoice_id !== null) {
            $related = Invoice::query()->find($invoice->related_invoice_id);
            $header[] = TextEntry::make('h_related')->label('Storna')->state($related?->toData()->title() ?? '—')->url($related === null ? null : InvoiceResource::getUrl('view', ['record' => $related]));
        }

        $components = [
            Section::make()->columns(count($header))->components($header),
            ...DocumentInfolist::parties($data->seller, $data->customer),
            DocumentInfolist::lines($data->lines),
        ];

        if ($data->totals !== null) {
            $components[] = DocumentInfolist::totals($data->totals);
        } elseif ($computed !== null) {
            $components[] = DocumentInfolist::totals($computed);
        }

        if ($invoice->status->isIssued() && $invoice->type === DocumentType::Invoice) {
            $components[] = $this->paymentsSection();
        }

        if ($invoice->notes !== null) {
            $components[] = Section::make('Note')->components([TextEntry::make('h_notes')->label('')->state($invoice->notes)]);
        }

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (): bool => ! $this->invoice()->status->isIssued()),
            Action::make('issue')
                ->label('Emetti')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('primary')
                ->visible(fn (): bool => ! $this->invoice()->status->isIssued())
                ->schema([DatePicker::make('issued_on')->label('Data del documento')->default(today())->required()->maxDate(today())])
                ->modalDescription('Il numero viene assegnato ora e il documento non potrà più cambiare.')
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        $issued = app(IssueInvoice::class)->issue($this->invoice()->id, CarbonImmutable::parse(is_string($data['issued_on'] ?? null) ? $data['issued_on'] : 'today'));

                        return $issued->title().' emessa';
                    });
                }),
            Action::make('xml')
                ->label('Scarica XML')
                ->icon(Heroicon::OutlinedCodeBracket)
                ->visible(fn (): bool => $this->invoice()->xml_file_id !== null)
                ->action(function (): StreamedResponse {
                    $export = app(ExportXml::class)->export($this->invoice()->id);

                    return response()->streamDownload(fn () => print $export['xml'], $export['filename'], ['Content-Type' => 'application/xml']);
                }),
            Action::make('pdf')
                ->label('Scarica PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->visible(fn (): bool => $this->invoice()->pdf_file_id !== null)
                ->action(function (): StreamedResponse {
                    $invoice = $this->invoice();
                    $file = app(ReadFile::class)->find($invoice->pdf_file_id ?? 0);
                    $contents = app(ReadFile::class)->contents($invoice->pdf_file_id ?? 0);

                    return response()->streamDownload(fn () => print $contents, $file !== null ? $file->filename : 'documento.pdf', ['Content-Type' => 'application/pdf']);
                }),
            Action::make('sent')
                ->label('Segna come inviata')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (): bool => $this->invoice()->status->canBeSent())
                ->schema([DatePicker::make('sent_on')->label('Caricata sul portale il')->default(today())->required()->maxDate(today())])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        app(MarkSentManually::class)->mark($this->invoice()->id, CarbonImmutable::parse(is_string($data['sent_on'] ?? null) ? $data['sent_on'] : 'today'));

                        return 'Segnata come inviata';
                    });
                }),
            Action::make('payment')
                ->label('Registra incasso')
                ->icon(Heroicon::OutlinedBanknotes)
                ->visible(fn (): bool => $this->invoice()->status->isOutstanding() && $this->invoice()->type === DocumentType::Invoice && $this->invoice()->toData()->outstanding()->isPositive())
                ->fillForm(fn (): array => ['amount' => $this->invoice()->toData()->outstanding()->toDecimalString(), 'paid_on' => today()->toDateString()])
                ->schema([
                    TextInput::make('amount')->label('Importo')->prefix('€')->required(),
                    DatePicker::make('paid_on')->label('Incassato il')->required(),
                    Select::make('account_id')->label('Sul conto')->options(fn (): array => app(Accounts::class)->options())->required(),
                ])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        app(RecordPayment::class)->record($this->invoice()->id, PaymentInput::fromArray($data));

                        return 'Incasso registrato';
                    });
                }),
            Action::make('creditNote')
                ->label('Nota di credito')
                ->icon(Heroicon::OutlinedReceiptRefund)
                ->color('gray')
                ->visible(fn (): bool => $this->invoice()->status->isOutstanding() && $this->invoice()->type === DocumentType::Invoice)
                ->requiresConfirmation()
                ->modalDescription('Crea una bozza di nota di credito a storno totale di questa fattura; potrai modificarla prima di emetterla.')
                ->action(function (): void {
                    $note = app(CreateCreditNote::class)->create($this->invoice()->id);
                    $this->redirect(InvoiceResource::getUrl('edit', ['record' => $note->id]));
                }),
        ];
    }

    private function paymentsSection(): Section
    {
        $payments = app(InvoicePayments::class)->forInvoice($this->invoice()->id);
        $entries = [];

        foreach ($payments as $payment) {
            $entries[] = TextEntry::make('p_'.$payment->movementId)->label('')
                ->state($payment->paidOn->isoFormat('D MMM YYYY').' · '.$payment->amount->format().' · '.$payment->description);
        }

        if ($entries === []) {
            $entries[] = TextEntry::make('p_none')->label('')->state('Nessun incasso registrato.');
        }

        return Section::make('Incassi')->compact()->components($entries)->headerActions([
            Action::make('removePayment')
                ->label('Rimuovi un incasso')
                ->color('gray')
                ->visible($payments !== [])
                ->schema([
                    Select::make('movement_id')->label('Incasso')->required()->options(self::paymentOptions($payments)),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        app(RemovePayment::class)->remove($this->invoice()->id, is_numeric($data['movement_id'] ?? null) ? (int) $data['movement_id'] : 0);

                        return 'Incasso rimosso';
                    });
                }),
        ]);
    }

    /**
     * @param  list<PaymentData>  $payments
     * @return array<int, string>
     */
    private static function paymentOptions(array $payments): array
    {
        $options = [];
        foreach ($payments as $payment) {
            $options[$payment->movementId] = $payment->paidOn->isoFormat('D MMM YYYY').' · '.$payment->amount->format();
        }

        return $options;
    }

    private function draftTotals(Invoice $invoice): ?DocumentTotals
    {
        if ($invoice->status->isIssued()) {
            return null;
        }

        try {
            return app(DocumentContext::class)->compute($invoice->lineInputs(), $invoice->currency)['totals'];
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * @param  callable(): string  $callback
     */
    private function run(callable $callback): void
    {
        try {
            $message = $callback();
        } catch (ValidationException $exception) {
            Notification::make()->title('Operazione non riuscita')->body(implode(' ', $exception->validator->errors()->all()))->danger()->persistent()->send();

            return;
        } catch (LogicException $exception) {
            Notification::make()->title('Operazione non riuscita')->body($exception->getMessage())->danger()->persistent()->send();

            return;
        }

        $this->record = $this->invoice()->refresh();
        Notification::make()->title($message)->success()->send();
    }

    private function invoice(): Invoice
    {
        $record = $this->getRecord();

        if (! $record instanceof Invoice) {
            throw new LogicException('The invoice page shows invoices.');
        }

        return $record;
    }
}
