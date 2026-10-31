<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Filament\Resources\PaymentRequests\Pages;

use App\Modules\Cash\Queries\Accounts;
use App\Modules\Contacts\Queries\FindContact;
use App\Modules\Files\Queries\ReadFile;
use App\Modules\Invoicing\Actions\ConvertPaymentRequest;
use App\Modules\Invoicing\Actions\PayPaymentRequest;
use App\Modules\Invoicing\Data\PartySnapshot;
use App\Modules\Invoicing\Data\PaymentInput;
use App\Modules\Invoicing\Enums\PaymentRequestStatus;
use App\Modules\Invoicing\Filament\DocumentInfolist;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Modules\Invoicing\Models\PaymentRequest;
use Filament\Actions\Action;
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

class ViewPaymentRequest extends ViewRecord
{
    protected static string $resource = PaymentRequestResource::class;

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();

        return $record instanceof PaymentRequest ? $record->toData()->title() : parent::getTitle();
    }

    public function content(Schema $schema): Schema
    {
        $request = $this->request();
        $data = $request->toData();
        $contact = app(FindContact::class)->resolve($request->contact_id);

        $header = [
            TextEntry::make('h_status')->label('Stato')->state($request->status->label())->badge()->color($request->status->color()),
            TextEntry::make('h_date')->label('Data')->state($data->issuedOn->isoFormat('D MMM YYYY')),
            TextEntry::make('h_due')->label('Scadenza')->state($data->dueOn?->isoFormat('D MMM YYYY') ?? '—'),
            TextEntry::make('h_paid')->label('Incassato')->state($data->paid->format().' su '.$data->total->format()),
        ];

        if ($request->invoice_id !== null) {
            $header[] = TextEntry::make('h_invoice')->label('Fattura')->state('apri')->url(InvoiceResource::getUrl('view', ['record' => $request->invoice_id]));
        }

        return $schema->components([
            Section::make()->columns(count($header))->components($header),
            ...DocumentInfolist::parties(null, $contact === null ? null : PartySnapshot::ofCustomer($contact)),
            DocumentInfolist::lines($data->lines),
            DocumentInfolist::totals($data->totals),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')->label('Scarica PDF')->icon(Heroicon::OutlinedDocumentArrowDown)
                ->visible(fn (): bool => $this->request()->pdf_file_id !== null)
                ->action(function (): StreamedResponse {
                    $contents = app(ReadFile::class)->contents($this->request()->pdf_file_id ?? 0);

                    return response()->streamDownload(fn () => print $contents, 'richiesta-'.$this->request()->number.'.pdf', ['Content-Type' => 'application/pdf']);
                }),
            Action::make('pay')->label('Registra incasso')->icon(Heroicon::OutlinedBanknotes)
                ->visible(fn (): bool => $this->request()->status === PaymentRequestStatus::Open)
                ->fillForm(fn (): array => ['amount' => $this->request()->toData()->total->minus($this->request()->toData()->paid)->toDecimalString(), 'paid_on' => today()->toDateString()])
                ->schema([
                    TextInput::make('amount')->label('Importo')->prefix('€')->required(),
                    DatePicker::make('paid_on')->label('Incassato il')->required(),
                    Select::make('account_id')->label('Sul conto')->options(fn (): array => app(Accounts::class)->options())->required(),
                ])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        app(PayPaymentRequest::class)->pay($this->request()->id, PaymentInput::fromArray($data));

                        return 'Incasso registrato';
                    });
                }),
            Action::make('convert')->label('Emetti la fattura')->icon(Heroicon::OutlinedDocumentCheck)->color('primary')
                ->visible(fn (): bool => $this->request()->status === PaymentRequestStatus::Paid)
                ->requiresConfirmation()
                ->modalDescription('La fattura viene emessa con la data del pagamento e risulta già incassata.')
                ->action(function (): void {
                    $this->run(function (): string {
                        $invoice = app(ConvertPaymentRequest::class)->convert($this->request()->id);

                        return $invoice->title().' emessa';
                    });
                }),
        ];
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

        $this->record = $this->request()->refresh();
        Notification::make()->title($message)->success()->send();
    }

    private function request(): PaymentRequest
    {
        $record = $this->getRecord();

        if (! $record instanceof PaymentRequest) {
            throw new LogicException('The page shows payment requests.');
        }

        return $record;
    }
}
