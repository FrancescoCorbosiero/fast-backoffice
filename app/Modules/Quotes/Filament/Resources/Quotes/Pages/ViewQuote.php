<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Filament\Resources\Quotes\Pages;

use App\Modules\Contacts\Filament\Components\ContactLink;
use App\Modules\Files\Queries\ReadFile;
use App\Modules\Invoicing\Data\PartySnapshot;
use App\Modules\Invoicing\Filament\Components\DocumentInfolist;
use App\Modules\Invoicing\Queries\DocumentFigures;
use App\Modules\Quotes\Actions\AcceptQuote;
use App\Modules\Quotes\Actions\DeclineQuote;
use App\Modules\Quotes\Actions\DeleteQuote;
use App\Modules\Quotes\Actions\ExtendQuoteValidity;
use App\Modules\Quotes\Actions\MarkQuoteSent;
use App\Modules\Quotes\Actions\RenderQuotePdf;
use App\Modules\Quotes\Enums\AcceptanceOutcome;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Filament\Resources\Quotes\QuoteResource;
use App\Modules\Quotes\Models\Quote;
use App\Support\Filament\FormErrors;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewQuote extends ViewRecord
{
    protected static string $resource = QuoteResource::class;

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();

        return $record instanceof Quote ? $record->toData()->title() : parent::getTitle();
    }

    public function content(Schema $schema): Schema
    {
        $data = $this->quote()->toData();
        $today = Date::today();
        $status = $data->statusOn($today);

        $customer = $this->customer();

        $header = [
            TextEntry::make('h_status')->label('Stato')->state($status->label())->badge()->color($status->color()),
            TextEntry::make('h_customer')->label('Cliente')->state($data->customerName)->url(ContactLink::url($data->contactId)),
            TextEntry::make('h_date')->label('Data')->state($data->issuedOn->isoFormat('D MMM YYYY')),
            TextEntry::make('h_valid')->label('Valido fino al')->state($data->validUntil->isoFormat('D MMM YYYY')),
            TextEntry::make('h_total')->label('Totale')->state($data->total->format())->weight('bold'),
        ];
        if ($data->subject !== null) {
            $header[] = TextEntry::make('h_subject')->label('Oggetto')->state($data->subject)->columnSpanFull();
        }
        if ($customer?->email !== null) {
            $header[] = TextEntry::make('h_email')->label('Email del cliente')->state($customer->email)->url('mailto:'.$customer->email)->icon(Heroicon::OutlinedEnvelope);
        }
        if ($data->sentOn !== null) {
            $header[] = TextEntry::make('h_sent')->label('Inviato il')->state($data->sentOn->isoFormat('D MMM YYYY'));
        }
        if ($data->decidedOn !== null) {
            $header[] = TextEntry::make('h_decided')->label($data->status === QuoteStatus::Accepted ? 'Accettato il' : 'Rifiutato il')->state($data->decidedOn->isoFormat('D MMM YYYY'));
        }
        if ($data->declineReason !== null) {
            $header[] = TextEntry::make('h_reason')->label('Motivo')->state($data->declineReason)->columnSpanFull();
        }
        if ($data->invoiceId !== null) {
            $header[] = TextEntry::make('h_invoice')->label('Fattura')->state('apri la bozza')->url(self::invoiceUrl($data->invoiceId));
        }
        if ($data->paymentRequestId !== null) {
            $header[] = TextEntry::make('h_request')->label('Richiesta di pagamento')->state('apri')->url(self::paymentRequestUrl($data->paymentRequestId));
        }

        $components = [Section::make()->columns(4)->components($header)];
        if ($customer !== null) {
            $components = [...$components, ...DocumentInfolist::parties(null, $customer, contactId: $data->contactId)];
        }
        $components[] = DocumentInfolist::lines($data->lines);
        $components[] = DocumentInfolist::totals($data->totals);
        if ($data->notes !== null) {
            $components[] = Section::make('Note')->compact()->components([TextEntry::make('h_notes')->label('')->state($data->notes)]);
        }

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (): bool => $this->quote()->status === QuoteStatus::Draft),
            // Editing a sent quote means re-sending it: the owner is told before the page opens.
            Action::make('edit-sent')->label('Modifica')->icon(Heroicon::PencilSquare)
                ->visible(fn (): bool => $this->quote()->status === QuoteStatus::Sent)
                ->requiresConfirmation()
                ->modalHeading('Modificare un preventivo già inviato?')
                ->modalDescription('Il preventivo torna in bozza e il PDF inviato viene scartato: dovrai segnarlo di nuovo come inviato. Per spostare solo la scadenza usa «Proroga validità».')
                ->modalSubmitActionLabel('Modifica')
                ->action(fn () => $this->redirect(QuoteResource::getUrl('edit', ['record' => $this->quote()]))),
            Action::make('extend')->label('Proroga validità')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
                ->visible(fn (): bool => $this->quote()->status->isOpen())
                ->fillForm(fn (): array => ['valid_until' => $this->quote()->valid_until->toDateString()])
                ->schema([DatePicker::make('valid_until')->label('Valido fino al')->required()->minDate(fn (): CarbonImmutable => $this->quote()->issued_on)])
                ->action(function (array $data, Schema $schema): void {
                    $this->run(function () use ($data): string {
                        app(ExtendQuoteValidity::class)->extend($this->quote()->id, self::date($data, 'valid_until') ?? $this->quote()->valid_until);

                        return 'Validità prorogata al '.$this->quote()->refresh()->valid_until->isoFormat('D MMM YYYY');
                    }, $schema);
                }),
            Action::make('pdf')->icon(Heroicon::OutlinedDocumentArrowDown)
                ->label(fn (): string => $this->sentPdfFileId() !== null ? 'PDF inviato' : 'Scarica PDF')
                ->tooltip(fn (): ?string => $this->sentPdfFileId() !== null ? 'Il PDF così come è stato inviato al cliente' : null)
                ->action(function (): StreamedResponse {
                    $data = $this->quote()->toData();
                    $fileId = $this->sentPdfFileId();
                    // After sending, the document the customer received, not a fresh render that later settings could change.
                    $contents = $fileId !== null ? app(ReadFile::class)->contents($fileId) : app(RenderQuotePdf::class)->render($data);

                    return response()->streamDownload(fn () => print $contents, RenderQuotePdf::fileName($data), ['Content-Type' => 'application/pdf']);
                }),
            Action::make('duplicate')->label('Duplica')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
                ->visible(fn (): bool => $this->quote()->status !== QuoteStatus::Draft)
                ->tooltip('Un nuovo preventivo per lo stesso cliente con le stesse righe, da rivedere')
                ->url(fn (): string => CreateQuote::duplicateUrl($this->quote()->id)),
            Action::make('sent')->label('Segna come inviato')->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (): bool => $this->quote()->status === QuoteStatus::Draft)
                ->modalDescription(fn (): string => ($email = $this->customer()?->email) !== null ? "Scarica il PDF e invialo a {$email}; qui resta una copia di quello che il cliente ha ricevuto." : 'Scarica il PDF e invialo al cliente; qui resta una copia di quello che ha ricevuto.')
                ->schema([DatePicker::make('sent_on')->label('Inviato il')->default(today())->required()])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        app(MarkQuoteSent::class)->mark($this->quote()->id, self::date($data, 'sent_on'));

                        return 'Preventivo inviato';
                    });
                }),
            Action::make('accept')->label('Accettato')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (): bool => $this->quote()->status->isOpen())
                ->schema([
                    Select::make('outcome')->label('Crea')->options(AcceptanceOutcome::options())->default(AcceptanceOutcome::InvoiceDraft->value)->required(),
                    DatePicker::make('accepted_on')->label('Accettato il')->default(today())->required(),
                ])
                ->action(function (array $data): void {
                    $outcome = AcceptanceOutcome::tryFrom(is_string($data['outcome'] ?? null) ? $data['outcome'] : '') ?? AcceptanceOutcome::InvoiceDraft;
                    $done = $this->run(function () use ($data, $outcome): string {
                        app(AcceptQuote::class)->accept($this->quote()->id, $outcome, self::date($data, 'accepted_on'));

                        return 'Preventivo accettato: '.$outcome->label().' creata';
                    });

                    // The next step is issuing what was just created: the page goes there.
                    $quote = $this->quote();
                    if ($done && $quote->invoice_id !== null) {
                        $this->redirect(self::invoiceUrl($quote->invoice_id));
                    } elseif ($done && $quote->payment_request_id !== null) {
                        $this->redirect(self::paymentRequestUrl($quote->payment_request_id));
                    }
                }),
            Action::make('decline')->label('Rifiutato')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (): bool => $this->quote()->status->isOpen())
                ->schema([
                    DatePicker::make('declined_on')->label('Rifiutato il')->default(today())->required(),
                    Textarea::make('reason')->label('Motivo')->rows(2)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        $reason = $data['reason'] ?? null;
                        app(DeclineQuote::class)->decline($this->quote()->id, is_string($reason) ? $reason : null, self::date($data, 'declined_on'));

                        return 'Preventivo rifiutato';
                    });
                }),
            Action::make('delete')->label('Elimina bozza')->icon(Heroicon::OutlinedTrash)->color('gray')
                ->visible(fn (): bool => $this->quote()->status === QuoteStatus::Draft)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(DeleteQuote::class)->delete($this->quote()->id);
                    Notification::make()->title('Bozza eliminata')->success()->send();
                    $this->redirect(QuoteResource::getUrl('index'));
                }),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function date(array $data, string $key): ?CarbonImmutable
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    /**
     * Runs a use case and tells how it went; with the modal's schema, the
     * use case's field errors land on the modal's own fields.
     *
     * @param  callable(): string  $callback
     * @return bool whether it succeeded
     */
    private function run(callable $callback, ?Schema $schema = null): bool
    {
        try {
            $message = $callback();
        } catch (ValidationException $exception) {
            if ($schema !== null) {
                FormErrors::onSchema($schema, $exception);
            }
            Notification::make()->title('Operazione non riuscita')->body(implode(' ', $exception->validator->errors()->all()))->danger()->persistent()->send();

            return false;
        } catch (LogicException $exception) {
            Notification::make()->title('Operazione non riuscita')->body($exception->getMessage())->danger()->persistent()->send();

            return false;
        }

        $this->record = $this->quote()->refresh();
        Notification::make()->title($message)->success()->send();

        return true;
    }

    /**
     * The customer as the studio's documents print them; null when the
     * contact is gone.
     */
    private function customer(): ?PartySnapshot
    {
        return rescue(fn (): PartySnapshot => app(DocumentFigures::class)->customer($this->quote()->contact_id), null, false);
    }

    /**
     * The PDF the customer actually received, kept when the quote was sent.
     */
    private function sentPdfFileId(): ?int
    {
        $quote = $this->quote();

        return $quote->status === QuoteStatus::Sent ? $quote->pdf_file_id : null;
    }

    /**
     * Invoicing's pages are not public to other modules; the panel's routes are.
     */
    private static function invoiceUrl(int $invoiceId): string
    {
        return url('/admin/invoices/'.$invoiceId);
    }

    private static function paymentRequestUrl(int $paymentRequestId): string
    {
        return url('/admin/payment-requests/'.$paymentRequestId);
    }

    private function quote(): Quote
    {
        $record = $this->getRecord();

        if (! $record instanceof Quote) {
            throw new LogicException('The page shows quotes.');
        }

        return $record;
    }
}
