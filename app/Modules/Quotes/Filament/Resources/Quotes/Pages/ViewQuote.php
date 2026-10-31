<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Filament\Resources\Quotes\Pages;

use App\Modules\Invoicing\Filament\Components\DocumentInfolist;
use App\Modules\Invoicing\Queries\DocumentFigures;
use App\Modules\Quotes\Actions\AcceptQuote;
use App\Modules\Quotes\Actions\DeclineQuote;
use App\Modules\Quotes\Actions\DeleteQuote;
use App\Modules\Quotes\Actions\MarkQuoteSent;
use App\Modules\Quotes\Actions\RenderQuotePdf;
use App\Modules\Quotes\Enums\AcceptanceOutcome;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Filament\Resources\Quotes\QuoteResource;
use App\Modules\Quotes\Models\Quote;
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

        $header = [
            TextEntry::make('h_status')->label('Stato')->state($status->label())->badge()->color($status->color()),
            TextEntry::make('h_date')->label('Data')->state($data->issuedOn->isoFormat('D MMM YYYY')),
            TextEntry::make('h_valid')->label('Valido fino al')->state($data->validUntil->isoFormat('D MMM YYYY')),
            TextEntry::make('h_total')->label('Totale')->state($data->total->format())->weight('bold'),
        ];
        if ($data->subject !== null) {
            $header[] = TextEntry::make('h_subject')->label('Oggetto')->state($data->subject)->columnSpanFull();
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
            $header[] = TextEntry::make('h_invoice')->label('Fattura')->state('apri la bozza')->url(url('/admin/invoices/'.$data->invoiceId));
        }
        if ($data->paymentRequestId !== null) {
            $header[] = TextEntry::make('h_request')->label('Richiesta di pagamento')->state('apri')->url(url('/admin/payment-requests/'.$data->paymentRequestId));
        }

        $customer = rescue(fn () => app(DocumentFigures::class)->customer($data->contactId), null, false);

        $components = [Section::make()->columns(4)->components($header)];
        if ($customer !== null) {
            $components = [...$components, ...DocumentInfolist::parties(null, $customer)];
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
            EditAction::make()->visible(fn (): bool => $this->quote()->status->isOpen()),
            Action::make('pdf')->label('Scarica PDF')->icon(Heroicon::OutlinedDocumentArrowDown)
                ->action(function (): StreamedResponse {
                    $data = $this->quote()->toData();
                    $contents = app(RenderQuotePdf::class)->render($data);

                    return response()->streamDownload(fn () => print $contents, RenderQuotePdf::fileName($data), ['Content-Type' => 'application/pdf']);
                }),
            Action::make('sent')->label('Segna come inviato')->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (): bool => $this->quote()->status === QuoteStatus::Draft)
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
                    $this->run(function () use ($data): string {
                        $outcome = AcceptanceOutcome::tryFrom(is_string($data['outcome'] ?? null) ? $data['outcome'] : '') ?? AcceptanceOutcome::InvoiceDraft;
                        app(AcceptQuote::class)->accept($this->quote()->id, $outcome, self::date($data, 'accepted_on'));

                        return 'Preventivo accettato: '.$outcome->label().' creata';
                    });
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

        $this->record = $this->quote()->refresh();
        Notification::make()->title($message)->success()->send();
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
