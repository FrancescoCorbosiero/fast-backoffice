<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Pages;

use App\Modules\Cash\Queries\Accounts;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Files\Queries\ReadFile;
use App\Modules\Invoicing\Actions\CreateCreditNote;
use App\Modules\Invoicing\Actions\ExportXml;
use App\Modules\Invoicing\Actions\IssueInvoice;
use App\Modules\Invoicing\Actions\MarkSentManually;
use App\Modules\Invoicing\Actions\RecordPayment;
use App\Modules\Invoicing\Actions\RemovePayment;
use App\Modules\Invoicing\Actions\SendToSdi;
use App\Modules\Invoicing\Actions\VoidInvoice;
use App\Modules\Invoicing\Data\DocumentTotals;
use App\Modules\Invoicing\Data\InvoiceData;
use App\Modules\Invoicing\Data\PaymentData;
use App\Modules\Invoicing\Data\PaymentInput;
use App\Modules\Invoicing\DocumentContext;
use App\Modules\Invoicing\Enums\DocumentType;
use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Enums\PaymentState;
use App\Modules\Invoicing\Filament\Components\DocumentInfolist;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Filament\Resources\RecurringInvoices\RecurringInvoiceResource;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\RecurringInvoice;
use App\Modules\Invoicing\Queries\InvoicePayments;
use App\Modules\Invoicing\Queries\SdiTransmissions;
use App\Modules\Settings\Queries\GetSdiSettings;
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

        if ($invoice->status === InvoiceStatus::Rejected) {
            $deadline = $data->resendDeadline();
            $header[] = TextEntry::make('h_resend')->label('Scartata da SdI')
                ->state($data->canBeResent($today) ? 'correggi e riemetti entro il '.($deadline?->isoFormat('D MMM YYYY') ?? '—') : 'finestra di reinvio chiusa: annulla ed emetti una nuova fattura')
                ->color('danger');
        }

        if (($days = $data->daysLeftToSend($today)) !== null) {
            $header[] = TextEntry::make('h_deadline')->label('Da inviare a SdI')->state($days < 0 ? 'in ritardo di '.abs($days).' giorni' : "entro {$days} giorni")->color($days < 3 ? 'danger' : 'warning');
        }

        if ($invoice->status->canBeSent() && ! app(SendToSdi::class)->isAvailable()) {
            // The settings page belongs to the Settings module: reached by its route, not its class.
            $header[] = TextEntry::make('h_pec')->label('Invio via PEC')->state('non configurato: imposta la casella PEC')->color('warning')
                ->url(route('filament.admin.pages.settings.sdi'));
        }

        if ($invoice->related_invoice_id !== null) {
            $related = Invoice::query()->find($invoice->related_invoice_id);
            $header[] = TextEntry::make('h_related')->label('Storna')->state($related?->toData()->title() ?? '—')->url($related === null ? null : InvoiceResource::getUrl('view', ['record' => $related]));
        }

        foreach ($this->creditNotes() as $note) {
            $header[] = TextEntry::make('h_note'.$note->id)->label('Stornata da')->state($note->toData()->title().' · '.$note->status->label())
                ->color($note->status->color())->url(InvoiceResource::getUrl('view', ['record' => $note]));
        }

        if ($invoice->recurring_invoice_id !== null) {
            $schedule = RecurringInvoice::query()->find($invoice->recurring_invoice_id);
            $header[] = TextEntry::make('h_recurring')->label('Fattura ricorrente')->state($schedule === null ? '—' : $schedule->name)
                ->url($schedule === null ? null : RecurringInvoiceResource::getUrl('edit', ['record' => $schedule]));
        }

        $components = [
            Section::make()->columns(count($header))->components($header),
            ...DocumentInfolist::parties($data->seller, $data->customer, contactId: $invoice->contact_id),
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

        if ($invoice->status->isIssued()) {
            $components[] = $this->sdiSection($data, $today);
        }

        if ($invoice->notes !== null) {
            $components[] = Section::make('Note')->components([TextEntry::make('h_notes')->label('')->state($invoice->notes)]);
        }

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (): bool => ! $this->invoice()->status->isIssued() || $this->invoice()->status === InvoiceStatus::Rejected),
            Action::make('sendSdi')
                ->label('Invia a SdI')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->visible(fn (): bool => $this->invoice()->status->canBeSent() && app(SendToSdi::class)->isAvailable())
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Il file '.($this->invoice()->sdi_file_name ?? '').' parte via PEC verso '.app(GetSdiSettings::class)->get()->sdiAddress.'. L\'esito arriva dalla casella PEC.')
                ->action(function (): void {
                    $this->run(function (): string {
                        $sent = app(SendToSdi::class)->send($this->invoice()->id);

                        return $sent->title().' inviata a SdI';
                    });
                }),
            Action::make('reissue')
                ->label('Riemetti corretta')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->visible(fn (): bool => $this->invoice()->toData()->canBeResent(CarbonImmutable::today()))
                ->requiresConfirmation()
                ->modalDescription('La fattura viene ricostruita dalle righe con lo stesso numero e la stessa data, pronta per un nuovo invio (sdi.md §6).')
                ->action(function (): void {
                    $this->run(function (): string {
                        $issued = app(IssueInvoice::class)->reissue($this->invoice()->id);

                        return $issued->title().' riemessa ('.$issued->sdiFileName.')';
                    });
                }),
            Action::make('void')
                ->label('Annulla')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->visible(fn (): bool => $this->invoice()->status === InvoiceStatus::Rejected)
                ->requiresConfirmation()
                ->modalDescription('La fattura scartata viene annullata: il numero resta usato e ne emetti una nuova.')
                ->action(function (): void {
                    $this->run(function (): string {
                        app(VoidInvoice::class)->void($this->invoice()->id);

                        return 'Fattura annullata';
                    });
                }),
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
            Action::make('remind')
                ->label('Sollecita')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('danger')
                ->visible(fn (): bool => $this->invoice()->status->isOutstanding() && $this->invoice()->type === DocumentType::Invoice && $this->invoice()->toData()->paymentState(CarbonImmutable::today()) === PaymentState::Overdue)
                ->disabled(fn (): bool => $this->customerEmail() === null)
                ->tooltip(fn (): string => $this->customerEmail() === null ? 'Il cliente non ha un indirizzo email: aggiungilo sulla sua scheda.' : 'Apre una email di sollecito al cliente')
                ->url(fn (): ?string => $this->reminderMailto(), shouldOpenInNewTab: true),
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
                ->visible(fn (): bool => $this->invoice()->status->isOutstanding() && $this->invoice()->type === DocumentType::Invoice && ! $this->isCoveredByCreditNotes())
                ->requiresConfirmation()
                ->modalDescription('Crea una bozza di nota di credito a storno totale di questa fattura; potrai modificarla prima di emetterla.')
                ->action(function (): void {
                    $note = app(CreateCreditNote::class)->create($this->invoice()->id);
                    $this->redirect(InvoiceResource::getUrl('edit', ['record' => $note->id]));
                }),
        ];
    }

    private function sdiSection(InvoiceData $data, CarbonImmutable $today): Section
    {
        $entries = [];
        if ($data->sentAt !== null) {
            $entries[] = TextEntry::make('s_sent')->label('Inviata')->state($data->sentAt->isoFormat('D MMM YYYY HH:mm').' via '.($data->sentChannel?->label() ?? '—'));
        }
        if ($data->sdiIdentifier !== null) {
            $entries[] = TextEntry::make('s_id')->label('Identificativo SdI')->state($data->sdiIdentifier);
        }
        if ($data->sdiOutcomeAt !== null) {
            $entries[] = TextEntry::make('s_outcome')->label('Esito SdI')->state($data->status->label().' · '.$data->sdiOutcomeAt->isoFormat('D MMM YYYY HH:mm'));
        }
        foreach (app(SdiTransmissions::class)->forInvoice($data->id) as $transmission) {
            $entries[] = TextEntry::make('s_t'.$transmission->id)->label('PEC '.$transmission->fileName)->state($transmission->transportStatus().' → '.$transmission->sentTo.($transmission->outcome !== null ? ' · esito '.$transmission->outcome : ''));
        }
        foreach ($data->sdiErrors as $index => $error) {
            $entries[] = TextEntry::make('s_e'.$index)->label('Errore '.$error['code'])->state($error['description'].($error['hint'] !== null ? ' — '.$error['hint'] : ''))->color('danger')->columnSpanFull();
        }
        if ($entries === []) {
            $entries[] = TextEntry::make('s_none')->label('')->state($data->daysLeftToSend($today) !== null ? 'Non ancora inviata a SdI.' : '—');
        }

        return Section::make('Sistema di Interscambio')->compact()->columns(2)->components($entries);
    }

    /**
     * One row per payment, with the account it landed on and its own
     * "Rimuovi": the movement goes with it.
     */
    private function paymentsSection(): Section
    {
        $payments = app(InvoicePayments::class)->forInvoice($this->invoice()->id);
        $accounts = app(Accounts::class)->options(activeOnly: false);
        $entries = [];

        foreach ($payments as $payment) {
            $entries[] = TextEntry::make('p_'.$payment->movementId)->label('')
                ->state($payment->paidOn->isoFormat('D MMM YYYY').' · '.$payment->amount->format().' · '.$payment->description.' · sul conto '.($accounts[$payment->accountId] ?? '—'))
                ->suffixAction($this->removePaymentAction($payment));
        }

        if ($entries === []) {
            $entries[] = TextEntry::make('p_none')->label('')->state('Nessun incasso registrato.');
        }

        return Section::make('Incassi')->compact()->components($entries);
    }

    private function removePaymentAction(PaymentData $payment): Action
    {
        return Action::make('removePayment'.$payment->movementId)
            ->label('Rimuovi')
            ->icon(Heroicon::OutlinedTrash)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Rimuovere l\'incasso?')
            ->modalDescription('Il movimento di cassa del '.$payment->paidOn->isoFormat('D MMM YYYY').' di '.$payment->amount->format().' viene eliminato e il residuo della fattura torna a salire.')
            ->action(function () use ($payment): void {
                $this->run(function () use ($payment): string {
                    app(RemovePayment::class)->remove($this->invoice()->id, $payment->movementId);

                    return 'Incasso rimosso';
                });
            });
    }

    /**
     * The credit notes that storno this invoice, oldest first.
     *
     * @return list<Invoice>
     */
    private function creditNotes(): array
    {
        return array_values(Invoice::query()->where('related_invoice_id', $this->invoice()->id)->where('type', DocumentType::CreditNote->value)->orderBy('id')->get()->all());
    }

    /**
     * A live credit note already covers the invoice: a draft (its total is
     * not known yet) or issued notes adding up to the invoice's total.
     */
    private function isCoveredByCreditNotes(): bool
    {
        $credited = 0;

        foreach ($this->creditNotes() as $note) {
            if (! $note->status->isOutstanding() && $note->status !== InvoiceStatus::Draft) {
                continue;
            }
            if ($note->status === InvoiceStatus::Draft) {
                return true;
            }
            $credited += $note->total_cents;
        }

        return $credited > 0 && $credited >= $this->invoice()->total_cents;
    }

    private function customerEmail(): ?string
    {
        return app(FindContact::class)->resolve($this->invoice()->contact_id)?->email;
    }

    /**
     * A reminder for the customer, ready in their mail client: the owner
     * reads it over and sends it.
     */
    private function reminderMailto(): ?string
    {
        $email = $this->customerEmail();
        if ($email === null) {
            return null;
        }

        $data = $this->invoice()->toData();
        $body = sprintf(
            "Gentile cliente,\n\nla %s del %s di %s risulta ancora da incassare per %s; la scadenza era il %s.\n\nResto a disposizione per qualsiasi chiarimento.\n",
            $data->title(),
            $data->issuedOn?->isoFormat('D MMMM YYYY') ?? '—',
            $data->total->format(),
            $data->outstanding()->format(),
            $data->dueOn?->isoFormat('D MMMM YYYY') ?? '—',
        );

        return 'mailto:'.$email.'?subject='.rawurlencode('Sollecito di pagamento: '.$data->title()).'&body='.rawurlencode($body);
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
